<?php

namespace ONGR\ElasticsearchBundle\Mapping\Yaml;

use ONGR\ElasticsearchBundle\Annotation\Embedded;
use ONGR\ElasticsearchBundle\Mapping\Caser;
use ONGR\ElasticsearchBundle\Mapping\Exception\DocumentParserException;

class DocumentParser
{
    /**
     * @var array Contains gathered objects which later adds to documents.
     */
    private $objects = [];

    /**
     * @var array Document properties aliases.
     */
    private $aliases = [];

    /**
     * @var array Local cache for document properties.
     */
    private $properties = [];

    /**
     * Parses documents by used annotations and returns mapping for elasticsearch with some extra metadata.
     *
     * @param $yaml
     *
     * @return array
     * @throws DocumentParserException
     */
    public function parse($yaml)
    {
        $document = current($yaml);
        $class = new \ReflectionClass(key($yaml));

        $fields = [];
        $aliases = $this->getAliases($document, $class, $fields);

        return [
            'type' => $document->type ?: Caser::snake($class->getShortName()),
            'properties' => $this->getProperties($document, $class),
            'fields' => array_filter(
                array_merge(
                    (array) $document,
                    $fields
                )
            ),
            'aliases' => $aliases,
            'objects' => $this->getObjects(),
            'namespace' => $class->getName(),
            'class' => $class->getShortName(),
        ];
    }

    /**
     * Returns property annotation data from reader.
     *
     * @param \ReflectionProperty $property
     *
     * @return Property|null
     */
    private function getPropertyAnnotationData(\ReflectionProperty $property)
    {
        $result = $this->reader->getPropertyAnnotation($property, self::PROPERTY_ANNOTATION);

        if ($result !== null && $result->name === null) {
            $result->name = Caser::snake($property->getName());
        }

        return $result;
    }

    /**
     * Returns Embedded annotation data from reader.
     *
     * @param \ReflectionProperty $property
     *
     * @return Embedded|null
     */
    private function getEmbeddedAnnotationData(\ReflectionProperty $property)
    {
        $result = $this->reader->getPropertyAnnotation($property, self::EMBEDDED_ANNOTATION);

        if ($result !== null && $result->name === null) {
            $result->name = Caser::snake($property->getName());
        }

        return $result;
    }

    /**
     * Returns meta field annotation data from reader.
     *
     * @param \ReflectionProperty $property
     *
     * @return array
     */
    private function getMetaFieldAnnotationData($property)
    {
        /** @var MetaField $annotation */
        $annotation = $this->reader->getPropertyAnnotation($property, self::ID_ANNOTATION);
        $annotation = $annotation ?: $this->reader->getPropertyAnnotation($property, self::PARENT_ANNOTATION);
        $annotation = $annotation ?: $this->reader->getPropertyAnnotation($property, self::TTL_ANNOTATION);

        if ($annotation === null) {
            return null;
        }

        $data = [
            'name' => $annotation->getName(),
            'settings' => $annotation->getSettings(),
        ];

        if ($annotation instanceof ParentDocument) {
            $data['settings']['type'] = $this->getDocumentType($annotation->class);
        }

        return $data;
    }

    /**
     * Returns objects used in document.
     *
     * @return array
     */
    private function getObjects()
    {
        return array_keys($this->objects);
    }

    /**
     * Finds aliases for every property used in document including parent classes.
     *
     * @param \ReflectionClass $reflectionClass
     * @param array            $metaFields
     *
     * @return array
     */
    private function getAliases($document, \ReflectionClass $reflectionClass, array &$metaFields = null)
    {
        $reflectionName = $reflectionClass->getName();

        // We skip cache in case $metaFields is given. This should not affect performance
        // because for each document this method is called only once. For objects it might
        // be called few times.
        if ($metaFields === null && array_key_exists($reflectionName, $this->aliases)) {
            return $this->aliases[$reflectionName];
        }

        $alias = [];
        $properties = $document->properties;

        foreach ($properties as $name => $property) {
            $type = $property;

            $name = Caser::camel($name);
            $property = $reflectionClass->getProperty($name);

            if ($type !== null) {
                $alias[$type->name] = [
                    'propertyName' => $name,
                ];

                if ($type instanceof Property) {
                    $alias[$type->name]['type'] = $type->type;
                }

                switch (true) {
                    case $property->isPublic():
                        $propertyType = 'public';
                        break;
                    case $property->isProtected():
                    case $property->isPrivate():
                        $propertyType = 'private';
                        $alias[$type->name]['methods'] = $this->getMutatorMethods(
                            $reflectionClass,
                            $name,
                            $type instanceof Property ? $type->type : null
                        );
                        break;
                    default:
                        $message = sprintf(
                            'Wrong property %s type of %s class types cannot '.
                            'be static or abstract.',
                            $name,
                            $reflectionName
                        );
                        throw new \LogicException($message);
                }
                $alias[$type->name]['propertyType'] = $propertyType;

                if ($type instanceof Embedded) {
                    $child = new \ReflectionClass($this->finder->getNamespace($type->class));
                    $alias[$type->name] = array_merge(
                        $alias[$type->name],
                        [
                            'type' => $this->getObjectMapping($type->class)['type'],
                            'multiple' => $type->multiple,
                            'aliases' => $this->getAliases($child),
                            'namespace' => $child->getName(),
                        ]
                    );
                }
            }
        }

        $this->aliases[$reflectionName] = $alias;

        return $this->aliases[$reflectionName];
    }

    /**
     * Checks if class have setter and getter, and returns them in array.
     *
     * @param \ReflectionClass $reflectionClass
     * @param string           $property
     *
     * @return array
     */
    private function getMutatorMethods(\ReflectionClass $reflectionClass, $property, $propertyType)
    {
        $camelCaseName = ucfirst(Caser::camel($property));
        $setterName = 'set'.$camelCaseName;
        if (!$reflectionClass->hasMethod($setterName)) {
            $message = sprintf(
                'Missing %s() method in %s class. Add it, or change property to public.',
                $setterName,
                $reflectionClass->getName()
            );
            throw new \LogicException($message);
        }

        if ($reflectionClass->hasMethod('get'.$camelCaseName)) {
            return [
                'getter' => 'get' . $camelCaseName,
                'setter' => $setterName
            ];
        }

        if ($propertyType === 'boolean') {
            if ($reflectionClass->hasMethod('is' . $camelCaseName)) {
                return [
                    'getter' => 'is' . $camelCaseName,
                    'setter' => $setterName
                ];
            }

            $message = sprintf(
                'Missing %s() or %s() method in %s class. Add it, or change property to public.',
                'get'.$camelCaseName,
                'is'.$camelCaseName,
                $reflectionClass->getName()
            );
            throw new \LogicException($message);
        }

        $message = sprintf(
            'Missing %s() method in %s class. Add it, or change property to public.',
            'get'.$camelCaseName,
            $reflectionClass->getName()
        );
        throw new \LogicException($message);
    }

    /**
     * Returns document type.
     *
     * @param string $document Format must be like AcmeBundle:Document.
     *
     * @return string
     */
    private function getDocumentType($document)
    {
        $namespace = $this->finder->getNamespace($document);
        $reflectionClass = new \ReflectionClass($namespace);
        $document = $this->getDocumentAnnotationData($reflectionClass);

        return empty($document->type) ? $reflectionClass->getShortName() : $document->type;
    }

    /**
     * Returns all defined properties including private from parents.
     *
     * @param \ReflectionClass $reflectionClass
     *
     * @return array
     */
    private function getDocumentPropertiesReflection(\ReflectionClass $reflectionClass)
    {
        if (in_array($reflectionClass->getName(), $this->properties)) {
            return $this->properties[$reflectionClass->getName()];
        }

        $properties = [];

        foreach ($reflectionClass->getProperties() as $property) {
            if (!in_array($property->getName(), $properties)) {
                $properties[$property->getName()] = $property;
            }
        }

        $parentReflection = $reflectionClass->getParentClass();
        if ($parentReflection !== false) {
            $properties = array_merge(
                $properties,
                array_diff_key($this->getDocumentPropertiesReflection($parentReflection), $properties)
            );
        }

        $this->properties[$reflectionClass->getName()] = $properties;

        return $properties;
    }

    /**
     * Returns properties of reflection class.
     *
     * @param \ReflectionClass $reflectionClass Class to read properties from.
     * @param array            $properties      Properties to skip.
     * @param bool             $flag            If false exludes properties, true only includes properties.
     *
     * @return array
     */
    private function getProperties($document, \ReflectionClass $reflectionClass, $properties = [], $flag = false)
    {
        $mapping = [];
        foreach ($document->properties as $name => $property) {
            $type = $property;

            if ((in_array($name, $properties) && !$flag)
                || (!in_array($name, $properties) && $flag)
                || empty($type)
            ) {
                continue;
            }

            $mapping[$type->name] = $type;
        }

        return $mapping;
    }

    /**
     * Returns object mapping.
     *
     * Loads from cache if it's already loaded.
     *
     * @param string $className
     *
     * @return array
     */
    private function getObjectMapping($className)
    {
        $namespace = $this->finder->getNamespace($className);

        if (array_key_exists($namespace, $this->objects)) {
            return $this->objects[$namespace];
        }

        $reflectionClass = new \ReflectionClass($namespace);

        switch (true) {
            case $this->reader->getClassAnnotation($reflectionClass, self::OBJECT_ANNOTATION):
                $type = 'object';
                break;
            case $this->reader->getClassAnnotation($reflectionClass, self::NESTED_ANNOTATION):
                $type = 'nested';
                break;
            default:
                throw new \LogicException(
                    sprintf(
                        '%s should have @Object or @Nested annotation to be used as embeddable object.',
                        $className
                    )
                );
        }

        $this->objects[$namespace] = [
            'type' => $type,
            'properties' => $this->getProperties($reflectionClass),
        ];

        return $this->objects[$namespace];
    }
}
