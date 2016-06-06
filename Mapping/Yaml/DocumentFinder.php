<?php

namespace ONGR\ElasticsearchBundle\Mapping\Yaml;

use Symfony\Component\Finder\Finder;

class DocumentFinder
{
    /**
     * @var array
     */
    private $bundles;

    /**
     * @var string Directory in bundle to load documents from.
     */
    const DOCUMENT_DIR = 'Resources/config/elasticsearch';

    /**
     * Constructor.
     *
     * @param array $bundles Parameter kernel.bundles from service container.
     */
    public function __construct(array $bundles)
    {
        $this->bundles = $bundles;
    }

    /**
     * Returns bundle class namespace else throws an exception.
     *
     * @param string $name
     *
     * @return string
     *
     * @throws \LogicException
     */
    public function getBundleClass($name)
    {
        if (array_key_exists($name, $this->bundles)) {
            return $this->bundles[$name];
        }

        throw new \LogicException(sprintf('Bundle \'%s\' does not exist.', $name));
    }

    /**
     * Returns a list of bundle document files.

     * @param string $bundle
     *
     * @return array
     */
    public function getBundleDocumentFiles($bundle)
    {
        $bundleClass = $this->getBundleClass($bundle);
        $refClass = new \ReflectionClass($bundleClass);
        $bundleDir = dirname($refClass->getFileName());
        $yamlDir = $bundleDir . '/Resources/config/elasticsearch/';

        if (!is_dir($yamlDir)) {
            return [];
        }

        $finder = new Finder();
        $files = $finder->files()->in($yamlDir)->name('*.yml');

        $paths = [];

        foreach ($files as $file) {
            $paths[] = $file->getPathname();
        }

        return $paths;
    }
}
