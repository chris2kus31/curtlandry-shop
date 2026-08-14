<?php

namespace Webkul\Core\Eloquent;

use Prettus\Repository\Contracts\CacheableInterface;
use Prettus\Repository\Eloquent\BaseRepository;
use Prettus\Repository\Helpers\CacheKeys;
use Prettus\Repository\Traits\CacheableRepository;

abstract class Repository extends BaseRepository implements CacheableInterface
{
    use CacheableRepository;

    /**
     * Get the cache key for the given method.
     *
     * Overrides the prettus/l5-repository trait for two reasons (see runbook
     * Appendix A — "payment methods page 30s hang"):
     *
     * 1. The trait includes $request->fullUrl() in the key, so every distinct
     *    URL mints new keys forever even though these repositories' results
     *    depend only on the query arguments. That grew the bookkeeping file
     *    (storage/framework/cache/repository-cache-keys.json) without bound.
     * 2. The trait rewrites that whole JSON file to disk on EVERY lookup (hit
     *    or miss). With a large file, config-heavy admin pages exceeded the
     *    30s worker timeout. We only persist a key the first time we see it;
     *    CleanCacheRepository still reads the file to invalidate on writes.
     *
     * @param  string  $method
     * @param  mixed  $args
     * @return string
     */
    public function getCacheKey($method, $args = null)
    {
        $group = get_called_class();

        $key = sprintf('%s@%s-%s', $group, $method, md5(serialize($args).$this->serializeCriteria()));

        if (! in_array($key, CacheKeys::getKeys($group))) {
            CacheKeys::putKey($group, $key);
        }

        return $key;
    }

    /**
     * Cache only enabled.
     *
     * @var array
     */
    protected $cacheOnly;

    /**
     * Cache except enabled.
     *
     * @var array
     */
    protected $cacheExcept;

    /**
     * Clean enabled.
     *
     * @var bool
     */
    protected $cleanEnabled;

    /**
     * Allowed clean.
     *
     * @return bool
     */
    public function allowedClean()
    {
        if (! isset($this->cleanEnabled)) {
            return config('repository.cache.clean.enabled', true);
        }

        return $this->cleanEnabled;
    }

    /**
     * Allowed cache.
     *
     * @return bool
     */
    protected function allowedCache($method)
    {
        $className = get_class($this);

        $cacheEnabled = config("repository.cache.repositories.{$className}.enabled", config('repository.cache.enabled', true));

        if (! $cacheEnabled) {
            return false;
        }

        $cacheOnly = isset($this->cacheOnly) ? $this->cacheOnly : config("repository.cache.repositories.{$className}.allowed.only", config('repository.cache.allowed.only', null));

        $cacheExcept = isset($this->cacheExcept) ? $this->cacheExcept : config("repository.cache.repositories.{$className}.allowed.except", config('repository.cache.allowed.only', null));

        if (is_array($cacheOnly)) {
            return in_array($method, $cacheOnly);
        }

        if (is_array($cacheExcept)) {
            return ! in_array($method, $cacheExcept);
        }

        if (is_null($cacheOnly) && is_null($cacheExcept)) {
            return true;
        }

        return false;
    }

    /**
     * Reset model.
     *
     * @throws RepositoryException
     */
    public function resetModel()
    {
        $this->makeModel();

        return $this;
    }

    /**
     * Find data by field and value.
     *
     * @param  string  $field
     * @param  string  $value
     * @param  array  $columns
     * @return mixed
     */
    public function findOneByField($field, $value = null, $columns = ['*'])
    {
        $model = $this->findByField($field, $value, $columns);

        return $model->first();
    }

    /**
     * Find data by field and value.
     *
     * @param  string  $field
     * @param  string  $value
     * @param  array  $columns
     * @return mixed
     */
    public function findOneWhere(array $where, $columns = ['*'])
    {
        $model = $this->findWhere($where, $columns);

        return $model->first();
    }

    /**
     * Find data by id.
     *
     * @param  int  $id
     * @param  array  $columns
     * @return mixed
     */
    public function find($id, $columns = ['*'])
    {
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->model->find($id, $columns);
        $this->resetModel();

        return $this->parserResult($model);
    }

    /**
     * Find data by id.
     *
     * @param  int  $id
     * @param  array  $columns
     * @return mixed
     */
    public function findOrFail($id, $columns = ['*'])
    {
        $this->applyCriteria();
        $this->applyScope();
        $model = $this->model->findOrFail($id, $columns);
        $this->resetModel();

        return $this->parserResult($model);
    }

    /**
     * Count results of repository.
     *
     * @param  string  $columns
     * @return int
     */
    public function count(array $where = [], $columns = '*')
    {
        $this->applyCriteria();
        $this->applyScope();

        if ($where) {
            $this->applyConditions($where);
        }

        $result = $this->model->count($columns);
        $this->resetModel();
        $this->resetScope();

        return $result;
    }

    /**
     * Sum.
     *
     * @param  string  $columns
     * @return mixed
     */
    public function sum($columns)
    {
        $this->applyCriteria();
        $this->applyScope();

        $sum = $this->model->sum($columns);
        $this->resetModel();

        return $sum;
    }

    /**
     * Avg.
     *
     * @param  string  $columns
     * @return mixed
     */
    public function avg($columns)
    {
        $this->applyCriteria();
        $this->applyScope();

        $avg = $this->model->avg($columns);
        $this->resetModel();

        return $avg;
    }

    /**
     * Get model.
     *
     * @return mixed
     */
    public function getModel()
    {
        return $this->model;
    }
}
