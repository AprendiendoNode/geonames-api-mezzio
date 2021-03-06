<?php

declare(strict_types=1);

namespace Geo\Mapper;

use App\Constants;
use ArrayObject;
use DateTime;
use DateTimeZone;
use Geo\Entity\CollectionInterface;
use Geo\Entity\EntityInterface;
use Laminas\Cache\Storage\StorageInterface;
use Laminas\InputFilter\InputFilterInterface;
use Laminas\Paginator\Paginator;
use Mariojrrc\Laminas\Paginator\MongoDBAdapter;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;

use function array_column;
use function array_map;
use function array_merge;
use function assert;
use function date;
use function getenv;
use function md5;
use function var_export;

abstract class Mapper implements MapperInterface
{
    protected Collection $mongoCollection;
    private string $entityClass;
    private string $collectionClass;
    private string $inputFilterClass;
    private ?StorageInterface $cache;

    public function __construct(
        Collection $collection,
        string $entityClass,
        string $collectionClass,
        string $inputFilterClass,
        ?StorageInterface $cache = null
    ) {
        $this->mongoCollection  = $collection;
        $this->entityClass      = $entityClass;
        $this->collectionClass  = $collectionClass;
        $this->inputFilterClass = $inputFilterClass;
        $this->cache            = $cache;
    }

    public function fetchById(string $id): ?EntityInterface
    {
        return $this->fetchOneBy(['_id' => $id], true);
    }

    public function fetchOneBy(array $conditions = [], bool $withDeleted = false): ?EntityInterface
    {
        $paginator = $this->fetchAllBy($conditions, $withDeleted);
        assert($paginator instanceof Paginator);
        if ($paginator->count() === 0) {
            return null;
        }

        return $paginator->getCurrentItems()->current();
    }

    public function fetchAllBy(
        array $conditions = [],
        bool $withDeleted = false,
        array $options = []
    ): CollectionInterface {
        if (! $withDeleted) {
            $conditions['deleted'] = ['$ne' => true];
        }

        $adapter = new MongoDBAdapter(
            $this->mongoCollection,
            $conditions,
            $options,
            $this->entityClass
        );

        return new $this->collectionClass($adapter);
    }

    public function insert(EntityInterface $entity): EntityInterface
    {
        $data              = $this->extractDataForStorage($entity);
        $now               = new DateTime(date('c'));
        $data['createdAt'] = new UTCDateTime($now);
        $data['updatedAt'] = $data['createdAt'];
        $this->mongoCollection->insertOne($data);

        return $this->createEntity(array_merge($entity->getArrayCopy(), $data));
    }

    protected function extractDataForStorage(EntityInterface $entity): array
    {
        $data        = $entity->getArrayCopy();
        $data['_id'] = $data['id'];
        unset($data['id']);

        return $data;
    }

    public function update(EntityInterface $entity, array $set): EntityInterface
    {
        $set['updatedAt'] = new UTCDateTime(new DateTime(date('c')));
        $this->mongoCollection->updateOne(['_id' => $entity->id()->toString()], ['$set' => $set]);

        return $this->createEntity(array_merge($entity->getArrayCopy(), $set));
    }

    public function delete(EntityInterface $entity): EntityInterface
    {
        $now = new DateTime(date('c'));
        $set = [
            'deleted' => true,
            'deletedAt' => new UTCDateTime($now),
        ];

        return $this->update($entity, $set);
    }

    public function remove(EntityInterface $entity): void
    {
        $this->mongoCollection->deleteOne(['_id' => $entity->id()->toString()]);
    }

    public function createEntity(array $data): EntityInterface
    {
        foreach (['createdAt', 'updatedAt', 'deletedAt'] as $key) {
            if (! isset($data[$key]) || ! ($data[$key] instanceof UTCDateTime)) {
                continue;
            }

            $data[$key] = $data[$key]->toDateTime()
                ->setTimeZone(new DateTimeZone(getenv('TIMEZONE') ?: Constants::TIMEZONE_DEFAULT))
                ->format('c');
        }

        return $this->entityClass::fromArray($data);
    }

    public function createEntityInputFilter(): InputFilterInterface
    {
        return new $this->inputFilterClass();
    }

    public function bulk(array $ids): array
    {
        $cacheKey = md5(self::class . __FUNCTION__ . var_export($ids, true));
        if ($this->cache !== null && $this->cache->hasItem($cacheKey)) {
            return $this->cache->getItem($cacheKey);
        }

        $result = $this->mongoCollection->find(['_id' => ['$in' => $ids]]);

        $items = array_column(array_map(
            static fn (ArrayObject $item) => [
                'id' => $item['_id'],
                'name' => $item['name'],
                'shortName' => $item['shortName'],
            ],
            $result->toArray()
        ), null, 'id');

        if ($this->cache !== null) {
            $this->cache->setItem($cacheKey, $items);
        }

        return $items;
    }
}
