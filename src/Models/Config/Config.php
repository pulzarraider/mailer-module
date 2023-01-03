<?php
declare(strict_types=1);

namespace Remp\MailerModule\Models\Config;

use Nette\Caching\Cache;
use Nette\Caching\Storage;
use Remp\MailerModule\Repositories\ConfigsRepository;

class Config
{
    const TYPE_STRING = 'string';
    const TYPE_INT = 'integer';
    const TYPE_TEXT = 'text';
    const TYPE_PASSWORD = 'password';
    const TYPE_HTML = 'html';
    const TYPE_SELECT = 'select';
    const TYPE_BOOLEAN = 'boolean';

    private bool $loaded = false;

    private ?array $items = null;

    public function __construct(
        private ConfigsRepository $configsRepository,
        private LocalConfig $localConfig,
        private Storage $cacheStorage
    ) {
    }

    public function get(string $name)
    {
        if (!$this->loaded) {
            $this->initAutoload();
        }

        if (isset($this->items[$name])) {
            $item = $this->items[$name];
            $value = $this->localConfig->exists($name)
                ? $this->localConfig->value($name)
                : $item->value;

            return $this->formatValue($value, $item->type);
        }

        $item = $this->configsRepository->loadByName($name);
        if ($item) {
            $value = $this->localConfig->exists($name)
                ? $this->localConfig->value($name)
                : $item->value;

            return $this->formatValue($value, $item->type);
        }

        throw new ConfigNotExistsException("Setting {$name} does not exists.");
    }

    public function initAutoload(bool $force = false): void
    {
        $cacheData = $this->cacheStorage->read('application_autoload_cache');
        if (!$force && $cacheData) {
            $this->items = $cacheData;
        } else {
            $items = $this->configsRepository->loadAllAutoload();
            foreach ($items as $item) {
                $this->items[$item->name] = (object)$item->toArray();
            }
            $this->cacheStorage->write('application_autoload_cache', $this->items, [Cache::EXPIRE => 60]);
        }
        $this->loaded = true;
    }

    private function formatValue($value, $type = 'string')
    {
        if ($type == self::TYPE_INT) {
            return (int)$value;
        }

        return $value;
    }
}
