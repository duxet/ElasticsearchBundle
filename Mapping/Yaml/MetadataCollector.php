<?php

namespace ONGR\ElasticsearchBundle\Mapping\Yaml;

use ONGR\ElasticsearchBundle\Mapping\Exception\DocumentParserException;
use Symfony\Component\Yaml\Yaml;

class MetadataCollector
{
    /**
     * @var DocumentFinder
     */
    private $finder;

    /**
     * @var DocumentParser
     */
    private $parser;

    /**
     * Bundles mappings local cache container. Could be stored as the whole bundle or as single document.
     * e.g. AcmeDemoBundle, AcmeDemoBundle:Product.
     *
     * @var mixed
     */
    private $mappings = [];

    /**
     * @param DocumentFinder $finder For finding documents.
     * @param DocumentParser $parser For reading document annotations.
     */
    public function __construct($finder, $parser)
    {
        $this->finder = $finder;
        $this->parser = $parser;
    }

    /**
     * Fetches bundles mapping from documents.
     *
     * @param string[] $bundles Elasticsearch manager config. You can get bundles list from 'mappings' node.
     * @return array
     */
    public function getMappings(array $bundles)
    {
        $output = [];
        foreach ($bundles as $bundle) {
            $mappings = $this->getBundleMapping($bundle);

            $alreadyDefinedTypes = array_intersect_key($mappings, $output);
            if (count($alreadyDefinedTypes)) {
                throw new \LogicException(
                    implode(',', array_keys($alreadyDefinedTypes)) .
                    ' type(s) already defined in other document, you can use the same ' .
                    'type only once in a manager definition.'
                );
            }

            $output = array_merge($output, $mappings);
        }

        return $output;
    }

    /**
     * Searches for documents in the bundle and tries to read them.
     *
     * @param string $name
     *
     * @return array Empty array on containing zero documents.
     */
    public function getBundleMapping($name)
    {
        if (!is_string($name)) {
            throw new \LogicException('getBundleMapping() in the Metadata collector expects a string argument only!');
        }

        if (isset($this->mappings[$name])) {
            return $this->mappings[$name];
        }

        // Handle the case when single document mapping requested
        if (strpos($name, ':') !== false) {
            list($bundle, $documentClass) = explode(':', $name);
            $documents = $this->finder->getBundleDocumentFiles($bundle);
            $documents = in_array($documentClass, $documents) ? [$documentClass] : [];
        } else {
            $documents = $this->finder->getBundleDocumentFiles($name);
            $bundle = $name;
        }

        $mappings = [];

        if (!count($documents)) {
            return [];
        }

        // Loop through documents found in bundle.
        foreach ($documents as $document) {
            $documentYaml = Yaml::parse(file_get_contents($document), false, false, true);
            $documentMapping = $this->parser->parse($documentYaml);

            if (!array_key_exists($documentMapping['type'], $mappings)) {
                $documentMapping['bundle'] = $bundle;
                $mappings = array_merge($mappings, [$documentMapping['type'] => $documentMapping]);
            } else {
                throw new \LogicException(
                    $bundle . ' has 2 same type names defined in the documents. ' .
                    'Type names must be unique!'
                );
            }
        }

        return $mappings;
    }

    /**
     * Retrieves prepared mapping to sent to the elasticsearch client.
     *
     * @param array $bundles Manager config.
     *
     * @return array|null
     */
    public function getClientMapping(array $bundles)
    {
        /** @var array $typesMapping Array of filtered mappings for the elasticsearch client*/
        $typesMapping = null;

        /** @var array $mappings All mapping info */
        $mappings = $this->getMappings($bundles);

        foreach ($mappings as $type => $mapping) {
            if (!empty($mapping['properties'])) {
                $typesMapping[$type] = array_filter(
                    array_merge(
                        ['properties' => $mapping['properties']],
                        $mapping['fields']
                    ),
                    function ($value) {
                        return (bool)$value || is_bool($value);
                    }
                );
            }
        }

        return $typesMapping;
    }

    /**
     * Returns fully qualified class name.
     *
     * @param string $className
     *
     * @return string
     */
    public function getClassName($className)
    {
        return $this->finder->getNamespace($className);
    }
}
