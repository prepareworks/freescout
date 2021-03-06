<?php

namespace Nwidart\Modules;

use Illuminate\Container\Container;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Macroable;

abstract class Module extends ServiceProvider
{
    use Macroable;

    /**
     * The laravel|lumen application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application|Laravel\Lumen\Application
     */
    protected $app;

    /**
     * The module name.
     *
     * @var
     */
    protected $name;

    /**
     * The module path.
     *
     * @var string
     */
    protected $path;

    /**
     * @var array of cached Json objects, keyed by filename
     */
    protected $moduleJson = [];

    /**
     * The constructor.
     *
     * @param Container $app
     * @param $name
     * @param $path
     */
    public function __construct(Container $app, $name, $path)
    {
        parent::__construct($app);
        $this->name = $name;
        $this->path = realpath($path);
    }

    /**
     * Get laravel instance.
     *
     * @return \Illuminate\Contracts\Foundation\Application|Laravel\Lumen\Application
     */
    public function getLaravel()
    {
        return $this->app;
    }

    /**
     * Get name.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Get name in lower case.
     *
     * @return string
     */
    public function getLowerName()
    {
        return strtolower($this->name);
    }

    /**
     * Get name in studly case.
     *
     * @return string
     */
    public function getStudlyName()
    {
        return Str::studly($this->name);
    }

    /**
     * Get name in snake case.
     *
     * @return string
     */
    public function getSnakeName()
    {
        return Str::snake($this->name);
    }

    /**
     * Get description.
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->get('description');
    }

    /**
     * Get alias.
     *
     * @return string
     */
    public function getAlias()
    {
        return $this->get('alias');
    }

    /**
     * Get priority.
     *
     * @return string
     */
    public function getPriority()
    {
        return $this->get('priority');
    }

    /**
     * Get module requirements.
     *
     * @return array
     */
    public function getRequires()
    {
        return $this->get('requires');
    }

    /**
     * Get path.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * Set path.
     *
     * @param string $path
     *
     * @return $this
     */
    public function setPath($path)
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        if (config('modules.register.translations', true) === true) {
            $this->registerTranslation();
        }

        if ($this->isLoadFilesOnBoot()) {
            try {
                $this->registerFiles();
            } catch (\Exception $e) {
                $e = \Eventy::filter('modules.register_error', $e, $this);
                if ($e) {
                    throw $e;
                }
            }
        }

        $this->fireEvent('boot');
    }

    /**
     * Register module's translation.
     *
     * @return void
     */
    protected function registerTranslation()
    {
        $lowerName = $this->getLowerName();

        $langPath = $this->getPath().'/Resources/lang';

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $lowerName);
        }
    }

    /**
     * Get json contents from the cache, setting as needed.
     *
     * @param string $file
     *
     * @return Json
     */
    public function json($file = null) : Json
    {
        if ($file === null) {
            $file = 'module.json';
        }

        return array_get($this->moduleJson, $file, function () use ($file) {
            // In this Laravel-Modules package caching is not working, so we need to implement it.
            // https://github.com/nWidart/laravel-modules/issues/659

            // Cache stores module.json files as arrays
            $cachedManifestsArray = $this->app['cache']->get($this->app['config']->get('modules.cache.key'));

            if ($cachedManifestsArray && count($cachedManifestsArray)) {
                foreach ($cachedManifestsArray as $manifest) {
                    // We found manifest data in cache
                    if (!empty($manifest['name']) && $manifest['name'] == $this->getName()) {
                        return $this->moduleJson[$file] = new Json($this->getPath().'/'.$file, $this->app['files'], $manifest);
                    }
                }
            }

            // We have to set `active` flag from DB modules table.
            $json = new Json($this->getPath().'/'.$file, $this->app['files']);
            $json->set('active', (int) \App\Module::isActive($json->get('alias')));

            return $this->moduleJson[$file] = $json;
            //return $this->moduleJson[$file] = new Json($this->getPath() . '/' . $file, $this->app['files']);
        });
    }

    /**
     * Get a specific data from json file by given the key.
     *
     * @param string $key
     * @param null   $default
     *
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->json()->get($key, $default);
    }

    /**
     * Get a specific data from composer.json file by given the key.
     *
     * @param $key
     * @param null $default
     *
     * @return mixed
     */
    public function getComposerAttr($key, $default = null)
    {
        return $this->json('composer.json')->get($key, $default);
    }

    /**
     * Register the module.
     */
    public function register()
    {
        $this->registerAliases();

        try {
            $this->registerProviders();
        } catch (\Exception $e) {
            $e = \Eventy::filter('modules.register_error', $e, $this);
            if ($e) {
                throw $e;
            }
        }

        if ($this->isLoadFilesOnBoot() === false) {
            $this->registerFiles();
        }

        $this->fireEvent('register');
    }

    /**
     * Register the module event.
     *
     * @param string $event
     */
    protected function fireEvent($event)
    {
        $this->app['events']->fire(sprintf('modules.%s.'.$event, $this->getLowerName()), [$this]);
    }

    /**
     * Register the aliases from this module.
     */
    abstract public function registerAliases();

    /**
     * Register the service providers from this module.
     */
    abstract public function registerProviders();

    /**
     * Get the path to the cached *_module.php file.
     *
     * @return string
     */
    abstract public function getCachedServicesPath();

    /**
     * Register the files from this module.
     */
    protected function registerFiles()
    {
        foreach ($this->get('files', []) as $file) {
            include $this->path.'/'.$file;
        }
    }

    /**
     * Handle call __toString.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->getStudlyName();
    }

    /**
     * Determine whether the given status same with the current module status.
     *
     * @param $status
     *
     * @return bool
     */
    public function isStatus($status) : bool
    {
        // echo "<pre>";
        // print_r($this->json());

        //return (int)\App\Module::isActive($this->getAlias()) == $status;
        return $this->get('active', 0) === $status;
    }

    /**
     * Determine whether the current module activated.
     *
     * @return bool
     */
    public function enabled() : bool
    {
        return $this->isStatus(1);
    }

    /**
     * Alternate for "enabled" method.
     *
     * @return bool
     *
     * @deprecated
     */
    public function active()
    {
        return $this->isStatus(1);
    }

    /**
     * Determine whether the current module not activated.
     *
     * @return bool
     *
     * @deprecated
     */
    public function notActive()
    {
        return !$this->active();
    }

    /**
     *  Determine whether the current module not disabled.
     *
     * @return bool
     */
    public function disabled() : bool
    {
        return !$this->enabled();
    }

    /**
     * Set active state for current module.
     *
     * @param $active
     *
     * @return bool
     */
    public function setActive($active)
    {
        \App\Module::setActive($this->getAlias(), $active);
        //return $this->json()->set('active', $active)->save();
    }

    /**
     * Disable the current module.
     */
    public function disable()
    {
        $this->fireEvent('disabling');

        $this->setActive(0);

        $this->fireEvent('disabled');
    }

    /**
     * Enable the current module.
     */
    public function enable()
    {
        $this->fireEvent('enabling');

        $this->setActive(1);

        $this->fireEvent('enabled');
    }

    /**
     * Delete the current module.
     *
     * @return bool
     */
    public function delete()
    {
        return $this->json()->getFilesystem()->deleteDirectory($this->getPath());
    }

    /**
     * Get extra path.
     *
     * @param string $path
     *
     * @return string
     */
    public function getExtraPath(string $path) : string
    {
        return $this->getPath().'/'.$path;
    }

    /**
     * Handle call to __get method.
     *
     * @param $key
     *
     * @return mixed
     */
    public function __get($key)
    {
        return $this->get($key);
    }

    /**
     * Check if can load files of module on boot method.
     *
     * @return bool
     */
    protected function isLoadFilesOnBoot()
    {
        return config('modules.register.files', 'register') === 'boot' &&
            // force register method if option == boot && app is AsgardCms
            !class_exists('\Modules\Core\Foundation\AsgardCms');
    }

    /**
     * Check if module is official.
     *
     * @return bool [description]
     */
    public function isOfficial()
    {
        return \App\Module::isOfficial($this->get('authorUrl'));
    }

    /**
     * Get module license from DB.
     *
     * @return [type] [description]
     */
    public function getLicense()
    {
        return \App\Module::getLicense($this->getAlias());
    }
}
