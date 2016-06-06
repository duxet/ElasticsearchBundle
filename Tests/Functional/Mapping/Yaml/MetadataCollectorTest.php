<?php

namespace ONGR\ElasticsearchBundle\Tests\Functional\Mapping\Yaml;

use ONGR\ElasticsearchBundle\Mapping\Yaml\MetadataCollector;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class MetadataCollectorTest extends WebTestCase
{
    /**
     * @var MetadataCollector
     */
    private $metadataCollector;

    /**
     * Initialize MetadataCollector.
     */
    public function setUp()
    {
        $container = $this->createClient()->getContainer();
        $this->metadataCollector = $container->get('es.yaml_metadata_collector');
    }

    /**
     * Test if function throws exception if ES type names are not unique.
     *
     * @expectedException \LogicException
     */
    public function testGetBundleMappingWithTwoSameESTypes()
    {
        $this->metadataCollector->getMappings(['AcmeBarBundle', 'AcmeBarBundle']);
    }

    /**
     * Test mapping getter when there are no bundles loaded from parser.
     *
     * @expectedException \LogicException
     * @expectedExceptionMessage Bundle 'acme' does not exist.
     */
    public function testGetBundleMappingWithNoBundlesLoaded()
    {
        $this->metadataCollector->getBundleMapping('acme');
    }

    /**
     * Test for getBundleMapping(). Make sure meta fields are excluded from mapping.
     */
    public function testGetBundleMapping()
    {
        $mapping = $this->metadataCollector->getBundleMapping('AcmeBarBundle');

        $properties = $mapping['person']['properties'];
        $this->assertArrayNotHasKey('_id', $properties);
        $this->assertArrayNotHasKey('_ttl', $properties);

        $aliases = $mapping['person']['aliases'];
        $this->assertArrayHasKey('_id', $aliases);
        $this->assertArrayHasKey('_ttl', $aliases);
    }
}
