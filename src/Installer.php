<?php
namespace paw\composer;

use Composer\Installer\LibraryInstaller;
use Composer\Package\PackageInterface;
use Composer\Package\CompletePackageInterface;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Util\Filesystem;
use paw\composer\InvalidPluginException;

class Installer extends LibraryInstaller
{
    const PLUGINS_FILE = 'mlaxwong/plugins.php';

    public function supports($packageType)
    {
        return $packageType == 'paw-plugin';
    }

    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);
        try {
            $this->addPlugin($package);
        } catch (InvalidPluginException $ex) {
            parent::uninstall($repo, $package);
            throw $ex;
        }
    }

    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);
        $initialPlugin = $this->removePlugin($initial);
        try {
            $this->addPlugin($target);
        } catch (InvalidPluginException $ex) {
            parent::update($repo, $target, $initial);
            if ($initialPlugin !== null) $this->registerPlugin($initial->getName(), $initialPlugin);
            throw $ex;
        }
    }

    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);
        $this->removePlugin($package);
    }

    protected function registerPlugin($name, array $plugin)
    {
        $plugins = $this->loadPlugins();
        $plugins[$name] = $plugin;
        $this->savePlugins($plugins);
    }

    protected function unregisterPlugin($name)
    {
        $plugins = $this->loadPlugins();
        if (!isset($plugins[$name])) {
            return null;
        }
        $plugin = $plugins[$name];
        unset($plugins[$name]);
        $this->savePlugins($plugins);
        return $plugin;
    }

    protected function addPlugin(PackageInterface $package)
    {
        $extra = $package->getExtra();
        $prettyName = $package->getPrettyName();

        $class = isset($extra['class']) ? $extra['class'] : null;
        $basePath = isset($extra['basePath']) ? $extra['basePath'] : null;
        $aliases = $this->generateDefaultAliases($package, $class, $basePath);

        if ($class === null) throw new InvalidPluginException($package, 'Unable to determine the Plugin class');

        if ($basePath === null) throw new InvalidPluginException($package, 'Unable to determine the base path');

        if (!isset($extra['handle']) || !preg_match('/^[a-zA-Z][\w\-]*$/', $extra['handle'])) 
        {
            throw new InvalidPluginException($package, 'Invalid or missing plugin handle');
        }

        $handle = $extra['handle'];
        if (strtolower($handle) !== $handle) 
        {
            $handle = strtolower(trim(str_replace('_', '-', preg_replace('/(?<![A-Z])[A-Z]/', '-' . '\0', $handle)), '-'));
            $handle = preg_replace('/\-{2,}/', '-', $handle);
            $this->io->write('<warning>' . $prettyName . ' uses the old plugin handle format ("' . $extra['handle'] . '"). It should be "' . $handle . '".</warning>');
        }

        $plugin = [
            'class' => $class,
            'basePath' => $basePath,
            'handle' => $handle,
        ];
        
        if ($aliases) $plugin['aliase'] = $aliases;

        if (strpos($prettyName, '/') !== false)  {
            list($vendor, $name) = explode('/', $prettyName);
        } else {
            $vendor = null;
            $name = $prettyName;
        }

        if (isset($extra['name'])) {
            $plugin['name'] = $extra['name'];
        } else {
            $plugin['name'] = $name;
        }

        if (isset($extra['version'])) {
            $plugin['version'] = $extra['version'];
        } else {
            $plugin['version'] = $package->getPrettyVersion();
        }

        if (isset($extra['description'])) {
            $plugin['description'] = $extra['description'];
        } else if ($package instanceof CompletePackageInterface && ($description = $package->getDescription())) {
            $plugin['description'] = $description;  
        }

        if (isset($extra['developer'])) {
            $plugin['developer'] = $extra['developer'];
        } else if ($authorName = $this->getAuthorProperty($package, 'name')) {
            $plugin['developer'] = $authorName;
        } else if ($vendor !== null) {
            $plugin['developer'] = $vendor;
        }

        $this->registerPlugin($package->getName(), $plugin);
    }

    protected function savePlugins(array $plugins)
    {
        $file = $this->vendorDir.'/'.static::PLUGINS_FILE;
        if (!file_exists(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        $array = str_replace("'<vendor-dir>", '$vendorDir . \'', var_export($plugins, true));
        file_put_contents($file, "<?php\n\n\$vendorDir = dirname(__DIR__);\n\nreturn $array;\n");
        // Invalidate opcache of plugins.php if it exists
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    protected function removePlugin(PackageInterface $package)
    {
        return $this->unregisterPlugin($package->getName());
    }

    protected function loadPlugins()
    {
        $file = $this->vendorDir.'/'.static::PLUGINS_FILE;
        if (!is_file($file)) {
            return array();
        }
        // Invalidate opcache of plugins.php if it exists
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
        /** @var array $plugins */
        $plugins = require($file);
        // Swap absolute paths with <vendor-dir> tags
        $vendorDir = str_replace('\\', '/', $this->vendorDir);
        $n = strlen($vendorDir);
        foreach ($plugins as &$plugin) {
            // basePath
            if (isset($plugin['basePath'])) {
                $path = str_replace('\\', '/', $plugin['basePath']);
                if (strpos($path.'/', $vendorDir.'/') === 0) {
                    $plugin['basePath'] = '<vendor-dir>'.substr($path, $n);
                }
            }
            // aliases
            if (isset($plugin['aliases'])) {
                foreach ($plugin['aliases'] as $alias => $path) {
                    $path = str_replace('\\', '/', $path);
                    if (strpos($path.'/', $vendorDir.'/') === 0) {
                        $plugin['aliases'][$alias] = '<vendor-dir>'.substr($path, $n);
                    }
                }
            }
        }
        return $plugins;
    }

    protected function getAuthorProperty(PackageInterface $package, $property)
    {
        if (!$package instanceof CompletePackageInterface) {
            return null;
        }
        $authors = $package->getAuthors();
        if (empty($authors)) {
            return null;
        }
        $firstAuthor = reset($authors);
        if (!isset($firstAuthor[$property])) {
            return null;
        }
        return $firstAuthor[$property];
    }

    protected function generateDefaultAliases(PackageInterface $package, &$class, &$basePath)
    {
        $autoload = $package->getAutoload();
        if (empty($autoload['psr-4'])) {
            return null;
        }
        $fs = new Filesystem();
        $vendorDir = $fs->normalizePath($this->vendorDir);
        $aliases = array();
        foreach ($autoload['psr-4'] as $namespace => $path) {
            if (is_array($path)) {
                // Yii doesn't support aliases that point to multiple base paths
                continue;
            }
            // Normalize $path to an absolute path
            if (!$fs->isAbsolutePath($path)) {
                $path = $this->vendorDir.'/'.$package->getPrettyName().'/'.$path;
            }
            $path = $fs->normalizePath($path);
            $alias = '@'.str_replace('\\', '/', trim($namespace, '\\'));
            if (strpos($path.'/', $vendorDir.'/') === 0) {
                $aliases[$alias] = '<vendor-dir>'.substr($path, strlen($vendorDir));
            } else {
                $aliases[$alias] = $path;
            }
            // If we're still looking for the primary Plugin class, see if it's in here
            if ($class === null && file_exists($path.'/Plugin.php')) {
                $class = $namespace.'Plugin';
            }
            // echo $basePath . "\n";
            // echo 'heyyyyyyyy';
            // If we're still looking for the base path but we know the primary Plugin class,
            // see if the class namespace matches up, and the file is in here.
            // If so, set the base path to whatever directory contains the plugin class.
            if ($basePath === null && $class !== null) {
                $n = strlen($namespace);
                if (strncmp($namespace, $class, $n) === 0) {
                    $testClassPath = $path.'/'.str_replace('\\', '/', substr($class, $n)).'.php';
                    if (file_exists($testClassPath)) {
                        $basePath = dirname($testClassPath);
                        // If the base path starts with the vendor dir path, swap with <vendor-dir>
                        if (strpos($basePath.'/', $vendorDir.'/') === 0) {
                            $basePath = '<vendor-dir>'.substr($basePath, strlen($vendorDir));
                        }
                    }
                }
            }
        }
        return $aliases;
    }
}