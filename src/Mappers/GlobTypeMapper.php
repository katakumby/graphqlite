<?php

declare(strict_types=1);

namespace TheCodingMachine\GraphQLite\Mappers;

use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\OutputType;
use Mouf\Composer\ClassNameMapper;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\Psr16Adapter;
use Symfony\Contracts\Cache\CacheInterface as CacheContractInterface;
use TheCodingMachine\CacheUtils\ClassBoundCache;
use TheCodingMachine\CacheUtils\ClassBoundCacheInterface;
use TheCodingMachine\CacheUtils\ClassBoundMemoryAdapter;
use TheCodingMachine\CacheUtils\FileBoundCache;
use TheCodingMachine\ClassExplorer\Glob\GlobClassExplorer;
use TheCodingMachine\GraphQLite\AnnotationReader;
use TheCodingMachine\GraphQLite\Annotations\Decorate;
use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Type;
use TheCodingMachine\GraphQLite\InputTypeGenerator;
use TheCodingMachine\GraphQLite\InputTypeUtils;
use TheCodingMachine\GraphQLite\NamingStrategyInterface;
use TheCodingMachine\GraphQLite\TypeGenerator;
use TheCodingMachine\GraphQLite\Types\MutableObjectType;
use TheCodingMachine\GraphQLite\Types\ResolvableMutableInputInterface;
use Webmozart\Assert\Assert;
use function array_keys;
use function class_exists;
use function filemtime;
use function str_replace;

/**
 * Scans all the classes in a given namespace of the main project (not the vendor directory).
 * Analyzes all classes and uses the @Type annotation to find the types automatically.
 *
 * Assumes that the container contains a class whose identifier is the same as the class name.
 */
final class GlobTypeMapper implements TypeMapperInterface
{
    /** @var string */
    private $namespace;
    /** @var AnnotationReader */
    private $annotationReader;
    /** @var CacheInterface */
    private $cache;
    /** @var int|null */
    private $globTtl;
    /** @var array<string,string> Maps a domain class to the GraphQL type annotated class */
    private $mapClassToTypeArray = [];
    /**
     * Maps a domain class to the GraphQL type annotated class
     * @var ClassBoundCacheInterface
     */
    private $mapClassToTypeCache;
    /** @var array<string,array<string,string>> Maps a domain class to one or many type extenders (with the @ExtendType annotation) The array of type extenders has a key and value equals to FQCN */
    private $mapClassToExtendTypeArray = [];
    /**
     * Maps a domain class to one or many type extenders (with the @ExtendType annotation) The array of type extenders has a key and value equals to FQCN (array<string,string>)
     * @var ClassBoundCacheInterface
     */
    private $mapClassToExtendTypeCache;
    /** @var array<string,string> Maps a GraphQL type name to the GraphQL type annotated class */
    private $mapNameToType = [];
    /**
     * Maps a GraphQL type name to the GraphQL type annotated class
     * @var ClassBoundCacheInterface
     */
    private $mapNameToTypeCache;
    /** @var array<string,array<string,string>> Maps a GraphQL type name to one or many type extenders (with the @ExtendType annotation) The array of type extenders has a key and value equals to FQCN */
    private $mapNameToExtendType = [];
    /**
     * Maps a GraphQL type name to one or many type extenders (with the @ExtendType annotation) The array of type extenders has a key and value equals to FQCN (array<string,string>)
     * @var ClassBoundCacheInterface
     */
    private $mapNameToExtendTypeCache;
    /** @var array<string,string[]> Maps a domain class to the factory method that creates the input type in the form [classname, methodname] */
    private $mapClassToFactory = [];
    /**
     * Maps a domain class to the factory method that creates the input type in the form [classname, methodname]
     * @var ClassBoundCacheInterface
     */
    private $mapClassToFactoryCache;
    /** @var array<string,string[]> Maps a GraphQL input type name to the factory method that creates the input type in the form [classname, methodname] */
    private $mapInputNameToFactory = [];
    /**
     * Maps a GraphQL input type name to the factory method that creates the input type in the form [classname, methodname]
     * @var ClassBoundCacheInterface
     */
    private $mapInputNameToFactoryCache;
    /** @var array<string,array<int, callable&array>> Maps a GraphQL type name to one or many decorators (with the @Decorator annotation) */
    private $mapInputNameToDecorator = [];
    /**
     * Maps a GraphQL type name to one or many decorators (with the @Decorator annotation) array<int, callable&array>
     * @var ClassBoundCacheInterface
     */
    private $mapInputNameToDecoratorCache;
    /** @var ContainerInterface */
    private $container;
    /** @var TypeGenerator */
    private $typeGenerator;
    /** @var int|null */
    private $mapTtl;
    /** @var bool */
    private $fullMapComputed = false;
    /** @var bool */
    private $fullMapClassToExtendTypeArrayComputed = false;
    /** @var bool */
    private $fullMapNameToExtendTypeArrayComputed = false;
    /** @var NamingStrategyInterface */
    private $namingStrategy;
    /** @var InputTypeGenerator */
    private $inputTypeGenerator;
    /** @var InputTypeUtils */
    private $inputTypeUtils;
    /**
     * The array of globbed classes.
     * Only instantiable classes are returned.
     * Key: fully qualified class name
     *
     * @var array<string,ReflectionClass>
     */
    private $classes;
    /** @var bool */
    private $recursive;
    /** @var RecursiveTypeMapperInterface */
    private $recursiveTypeMapper;
    /** @var CacheContractInterface */
    private $cacheContract;

    /**
     * @param string $namespace The namespace that contains the GraphQL types (they must have a `@Type` annotation)
     */
    public function __construct(string $namespace, TypeGenerator $typeGenerator, InputTypeGenerator $inputTypeGenerator, InputTypeUtils $inputTypeUtils, ContainerInterface $container, AnnotationReader $annotationReader, NamingStrategyInterface $namingStrategy, RecursiveTypeMapperInterface $recursiveTypeMapper, CacheInterface $cache, ?int $globTtl = 2, ?int $mapTtl = null, bool $recursive = true)
    {
        $this->namespace           = $namespace;
        $this->typeGenerator       = $typeGenerator;
        $this->container           = $container;
        $this->annotationReader    = $annotationReader;
        $this->namingStrategy      = $namingStrategy;
        $this->cache               = $cache;
        $cachePrefix = str_replace(['\\', '{', '}', '(', ')', '/', '@', ':'], '_', $namespace);
        $this->cacheContract       = new Psr16Adapter($this->cache, $cachePrefix, $this->globTtl ?? 0);
        $this->globTtl             = $globTtl;
        $this->mapTtl              = $mapTtl;
        $this->inputTypeGenerator  = $inputTypeGenerator;
        $this->inputTypeUtils      = $inputTypeUtils;
        $this->recursive           = $recursive;
        $this->recursiveTypeMapper = $recursiveTypeMapper;
        $this->mapClassToTypeCache = new ClassBoundMemoryAdapter(new ClassBoundCache(new FileBoundCache($this->cache, 'globTypeMapperByClass_' . $cachePrefix)));
        $this->mapNameToTypeCache = new ClassBoundMemoryAdapter(new ClassBoundCache(new FileBoundCache($this->cache, 'globTypeMapperByName_' . $cachePrefix)));
        $this->mapNameToExtendTypeCache = new ClassBoundMemoryAdapter(new ClassBoundCache(new FileBoundCache($this->cache, 'globExtendTypeMapperByName_' . $cachePrefix)));
        $this->mapClassToFactoryCache = new ClassBoundMemoryAdapter(new ClassBoundCache(new FileBoundCache($this->cache, 'globInputTypeMapperByClass_' . $cachePrefix)));
        $this->mapInputNameToFactoryCache = new ClassBoundMemoryAdapter(new ClassBoundCache(new FileBoundCache($this->cache, 'globInputTypeMapperByName_' . $cachePrefix)));
        $this->mapInputNameToDecoratorCache = new ClassBoundMemoryAdapter(new ClassBoundCache(new FileBoundCache($this->cache, 'globDecoratorMapperByName_' . $cachePrefix)));
        $this->mapClassToExtendTypeCache = new ClassBoundMemoryAdapter(new ClassBoundCache(new FileBoundCache($this->cache, 'globExtendTypeMapperByClass_' . $cachePrefix)));
    }

    /**
     * Returns an array of fully qualified class names.
     *
     * @return array<string, array<string,string>>
     */
    private function getMaps(): array
    {
        if ($this->fullMapComputed === false) {
            [
                'mapClassToTypeArray' => $this->mapClassToTypeArray,
                'mapNameToType' => $this->mapNameToType,
                'mapClassToFactory' => $this->mapClassToFactory,
                'mapInputNameToFactory' => $this->mapInputNameToFactory,
                'mapInputNameToDecorator' => $this->mapInputNameToDecorator,
            ] = $this->cacheContract->get('fullMapComputed', function () {
                $this->buildMap();

                return [
                    'mapClassToTypeArray' => $this->mapClassToTypeArray,
                    'mapNameToType' => $this->mapNameToType,
                    'mapClassToFactory' => $this->mapClassToFactory,
                    'mapInputNameToFactory' => $this->mapInputNameToFactory,
                    'mapInputNameToDecorator' => $this->mapInputNameToDecorator,
                ];
            });

            $this->fullMapComputed = true;
        }

        return [
            'mapClassToTypeArray' => $this->mapClassToTypeArray,
            'mapNameToType' => $this->mapNameToType,
            'mapClassToFactory' => $this->mapClassToFactory,
            'mapInputNameToFactory' => $this->mapInputNameToFactory,
            'mapInputNameToDecorator' => $this->mapInputNameToDecorator,
        ];
    }

    /**
     * @return array<string,string> Maps a domain class to the GraphQL type annotated class
     */
    private function getMapClassToType(): array
    {
        return $this->getMaps()['mapClassToTypeArray'];
    }

    /**
     * @return array<string,string> Maps a GraphQL type name to the GraphQL type annotated class
     */
    private function getMapNameToType(): array
    {
        return $this->getMaps()['mapNameToType'];
    }

    /**
     * @return array<string,string[]> Maps a domain class to the factory method that creates the input type in the form [classname, methodname]
     */
    private function getMapClassToFactory(): array
    {
        return $this->getMaps()['mapClassToFactory'];
    }

    /**
     * @return array<string,string[]> Maps a GraphQL input type name to the factory method that creates the input type in the form [classname, methodname]
     */
    private function getMapInputNameToFactory(): array
    {
        return $this->getMaps()['mapInputNameToFactory'];
    }

    /**
     * @return array<string,array<int, callable&array>> Maps a GraphQL type name to one or many decorators (with the @Decorator annotation)
     */
    private function getMapInputNameToDecorator(): array
    {
        return $this->getMaps()['mapInputNameToDecorator'];
    }

    /**
     * @return array<string,array<string,string>> Maps a domain class to one or many type extenders (with the @ExtendType annotation) The array of type extenders has a key and value equals to FQCN
     */
    private function getMapClassToExtendTypeArray(): array
    {
        if ($this->fullMapClassToExtendTypeArrayComputed === false) {
            $this->mapClassToExtendTypeArray = $this->cacheContract->get('globTypeMapperExtend', function () {
                $this->buildMapClassToExtendTypeArray();

                return $this->mapClassToExtendTypeArray;
            });

            $this->fullMapClassToExtendTypeArrayComputed = true;
        }

        return $this->mapClassToExtendTypeArray;
    }

    /**
     * @return array<string,array<string,string>> Maps a GraphQL type name to one or many type extenders (with the @ExtendType annotation) The array of type extenders has a key and value equals to FQCN
     */
    private function getMapNameToExtendType(): array
    {
        if ($this->fullMapNameToExtendTypeArrayComputed === false) {
            $this->mapNameToExtendType = $this->cacheContract->get('globTypeMapperExtend_names', function () {
                $this->buildMapNameToExtendTypeArray();

                return $this->mapNameToExtendType;
            });

            $this->fullMapNameToExtendTypeArrayComputed = true;
        }

        return $this->mapNameToExtendType;
    }

    /**
     * Returns the array of globbed classes.
     * Only instantiable classes are returned.
     *
     * @return array<string,ReflectionClass> Key: fully qualified class name
     */
    private function getClassList(): array
    {
        if ($this->classes === null) {
            $this->classes = [];
            $explorer      = new GlobClassExplorer($this->namespace, $this->cache, $this->globTtl, ClassNameMapper::createFromComposerFile(null, null, true), $this->recursive);
            $classes       = $explorer->getClasses();
            foreach ($classes as $className) {
                if (! class_exists($className)) {
                    continue;
                }
                $refClass = new ReflectionClass($className);
                if (! $refClass->isInstantiable()) {
                    continue;
                }
                $this->classes[$className] = $refClass;
            }
        }

        return $this->classes;
    }

    private function buildMap(): void
    {
        $this->mapClassToTypeArray     = [];
        $this->mapNameToType           = [];
        $this->mapClassToFactory       = [];
        $this->mapInputNameToFactory   = [];
        $this->mapInputNameToDecorator = [];

        /** @var ReflectionClass[] $classes */
        $classes = $this->getClassList();
        foreach ($classes as $className => $refClass) {
            $type = $this->annotationReader->getTypeAnnotation($refClass);

            if ($type !== null) {
                if (isset($this->mapClassToTypeArray[$type->getClass()])) {
                    /*if ($this->mapClassToTypeArray[$type->getClass()] === $className) {
                        // Already mapped. Let's continue
                        continue;
                    }*/
                    throw DuplicateMappingException::createForType($type->getClass(), $this->mapClassToTypeArray[$type->getClass()], $className);
                }
                $this->storeTypeInCache($className, $type, $refClass);
            }

            $isAbstract = $refClass->isAbstract();

            foreach ($refClass->getMethods() as $method) {
                if (! $method->isPublic() || ($isAbstract && ! $method->isStatic())) {
                    continue;
                }
                $factory = $this->annotationReader->getFactoryAnnotation($method);

                if ($factory !== null) {
                    [$inputName, $className] = $this->inputTypeUtils->getInputTypeNameAndClassName($method);

                    if ($factory->isDefault()) {
                        if (isset($this->mapClassToFactory[$className])) {
                            throw DuplicateMappingException::createForFactory($className, $this->mapClassToFactory[$className][0], $this->mapClassToFactory[$className][1], $refClass->getName(), $method->name);
                        }
                    } else {
                        // If this is not the default factory, let's not map the class name to the factory.
                        $className = null;
                    }
                    $this->storeInputTypeInCache($method, $inputName, $className, $refClass);
                }

                $decorator = $this->annotationReader->getDecorateAnnotation($method);

                if ($decorator === null) {
                    continue;
                }

                $this->storeDecoratorMapperByNameInCache($method, $decorator);
            }
        }
    }

    private function buildMapClassToExtendTypeArray(): void
    {
        $this->mapClassToExtendTypeArray = [];
        $classes                         = $this->getClassList();
        foreach ($classes as $className => $refClass) {
            $extendType = $this->annotationReader->getExtendTypeAnnotation($refClass);

            if ($extendType === null) {
                continue;
            }

            $this->storeExtendTypeMapperByClassInCache($className, $extendType, $refClass);
        }
    }

    private function buildMapNameToExtendTypeArray(): void
    {
        $this->mapNameToExtendType = [];
        $classes                   = $this->getClassList();
        foreach ($classes as $className => $refClass) {
            $extendType = $this->annotationReader->getExtendTypeAnnotation($refClass);

            if ($extendType === null) {
                continue;
            }

            $this->storeExtendTypeMapperByNameInCache($className, $extendType, $refClass);
        }
    }

    /**
     * Stores in cache the mapping TypeClass <=> Object class <=> GraphQL type name.
     */
    private function storeTypeInCache(string $typeClassName, Type $type, ReflectionClass $reflectionClass): void
    {
        $objectClassName                             = $type->getClass();
        $this->mapClassToTypeArray[$objectClassName] = $typeClassName;
        $this->mapClassToTypeCache->set($objectClassName, $typeClassName, $reflectionClass, $this->mapTtl);

        $typeName                       = $this->namingStrategy->getOutputTypeName($typeClassName, $type);
        $this->mapNameToType[$typeName] = $typeClassName;
        $this->mapNameToTypeCache->set($typeName, $typeClassName, $reflectionClass, $this->mapTtl);
    }

    /**
     * Stores in cache the mapping between InputType name <=> Object class
     */
    private function storeInputTypeInCache(ReflectionMethod $refMethod, string $inputName, ?string $className, ReflectionClass $refClass): void
    {
        $refArray = [$refMethod->getDeclaringClass()->getName(), $refMethod->getName()];
        if ($className !== null) {
            $this->mapClassToFactory[$className] = $refArray;
            $this->mapClassToFactoryCache->set($className, $refArray, $refClass, $this->mapTtl);
        }
        $this->mapInputNameToFactory[$inputName] = $refArray;
        $this->mapInputNameToFactoryCache->set($inputName, $refArray, $refClass, $this->mapTtl);
    }

    /**
     * Stores in cache the mapping ExtendTypeClass <=> Object class.
     */
    private function storeExtendTypeMapperByClassInCache(string $extendTypeClassName, ExtendType $extendType, ReflectionClass $refClass): void
    {
        $objectClassName                                                         = $extendType->getClass();
        $this->mapClassToExtendTypeArray[$objectClassName][$extendTypeClassName] = $extendTypeClassName;
        $this->mapClassToExtendTypeCache->set($objectClassName, $this->mapClassToExtendTypeArray[$objectClassName], $refClass, $this->mapTtl);
    }

    /**
     * Stores in cache the mapping ExtendTypeClass <=> name class.
     */
    private function storeExtendTypeMapperByNameInCache(string $extendTypeClassName, ExtendType $extendType, ReflectionClass $refClass): void
    {
        $targetType = $this->recursiveTypeMapper->mapClassToType($extendType->getClass(), null);
        $typeName   = $targetType->name;

        $this->mapNameToExtendType[$typeName][$extendTypeClassName] = $extendTypeClassName;

        $this->mapNameToExtendTypeCache->set($typeName, $this->mapNameToExtendType[$typeName], $refClass, $this->mapTtl);
    }

    /**
     * Stores in cache the mapping ExtendTypeClass <=> name class.
     */
    private function storeDecoratorMapperByNameInCache(ReflectionMethod $reflectionMethod, Decorate $decorate): void
    {
        $typeName                                   = $decorate->getInputTypeName();
        $this->mapInputNameToDecorator[$typeName][] = [$reflectionMethod->getDeclaringClass()->getName(), $reflectionMethod->getName()];
        $this->mapInputNameToDecoratorCache->set($typeName, $this->mapInputNameToDecorator[$typeName], $reflectionMethod->getDeclaringClass(), $this->mapTtl);
    }

    private function getTypeFromCacheByObjectClass(string $className): ?string
    {
        if (isset($this->mapClassToTypeArray[$className])) {
            return $this->mapClassToTypeArray[$className];
        }

        // TODO: we need a memory adapter for ClassBoundCache
        // TODO: we need a memory adapter for ClassBoundCache
        // TODO: we need a memory adapter for ClassBoundCache
        // TODO: we need a memory adapter for ClassBoundCache
        // TODO: we need a memory adapter for ClassBoundCache
        // TODO: we need a memory adapter for ClassBoundCache
        // TODO: we need a memory adapter for ClassBoundCache
        // TODO: we need a memory adapter for ClassBoundCache
        // TODO: + buildMap must RETURN the full map (instead of storing it)
        // TODO: + buildMap must RETURN the full map (instead of storing it)
        // TODO: + buildMap must RETURN the full map (instead of storing it)
        // TODO: + buildMap must RETURN the full map (instead of storing it)
        // TODO: + buildMap must RETURN the full map (instead of storing it)
        // TODO: + buildMap must RETURN the full map (instead of storing it)
        // TODO: + buildMap must RETURN the full map (instead of storing it)

        // Let's try from the cache
        return $this->mapClassToTypeArray[$className] = $this->mapClassToTypeCache->get($className);
    }

    private function getTypeFromCacheByGraphQLTypeName(string $graphqlTypeName): ?string
    {
        if (isset($this->mapNameToType[$graphqlTypeName])) {
            return $this->mapNameToType[$graphqlTypeName];
        }

        // Let's try from the cache
        return $this->mapNameToType[$graphqlTypeName] = $this->mapNameToTypeCache->get($graphqlTypeName);
    }

    /**
     * @return string[]|null A pointer to the factory [$className, $methodName] or null on cache miss
     */
    private function getFactoryFromCacheByObjectClass(string $className): ?array
    {
        if (isset($this->mapClassToFactory[$className])) {
            return $this->mapClassToFactory[$className];
        }

        // Let's try from the cache
        return $this->mapClassToFactory[$className] = $this->mapClassToFactoryCache->get($className);
    }

    /**
     * @return array<string,string>|null An array of classes with the @ExtendType annotation (key and value = FQCN)
     */
    private function getExtendTypesFromCacheByObjectClass(string $className): ?array
    {
        if (isset($this->mapClassToExtendTypeArray[$className])) {
            return $this->mapClassToExtendTypeArray[$className];
        }

        // Let's try from the cache
        return $this->mapClassToExtendTypeArray[$className] = $this->mapClassToExtendTypeCache->get($className);
    }

    /**
     * @return array<string,string>|null An array of classes with the @ExtendType annotation (key and value = FQCN)
     */
    private function getExtendTypesFromCacheByGraphQLTypeName(string $graphqlTypeName): ?array
    {
        if (isset($this->mapNameToExtendType[$graphqlTypeName])) {
            return $this->mapNameToExtendType[$graphqlTypeName];
        }

        // Let's try from the cache
        return $this->mapNameToExtendType[$graphqlTypeName] = $this->mapNameToExtendTypeCache->get($graphqlTypeName);
    }

    /**
     * @return string[]|null A pointer to the factory [$className, $methodName] or null on cache miss
     */
    private function getFactoryFromCacheByGraphQLInputTypeName(string $graphqlTypeName): ?array
    {
        if (isset($this->mapInputNameToFactory[$graphqlTypeName])) {
            return $this->mapInputNameToFactory[$graphqlTypeName];
        }

        // Let's try from the cache
        return $this->mapInputNameToFactory[$graphqlTypeName] = $this->mapInputNameToFactoryCache->get($graphqlTypeName);
    }

    /**
     * @return array<int, string[]>|null A pointer to the decorators methods [$className, $methodName] or null on cache miss
     */
    private function getDecorateFromCacheByGraphQLInputTypeName(string $graphqlTypeName): ?array
    {
        if (isset($this->mapInputNameToDecorator[$graphqlTypeName])) {
            return $this->mapInputNameToDecorator[$graphqlTypeName];
        }

        // Let's try from the cache
        return $this->mapInputNameToDecorator[$graphqlTypeName] = $this->mapInputNameToDecoratorCache->get($graphqlTypeName);
    }

    /**
     * Returns true if this type mapper can map the $className FQCN to a GraphQL type.
     */
    public function canMapClassToType(string $className): bool
    {
        $typeClassName = $this->getTypeFromCacheByObjectClass($className);

        if ($typeClassName !== null) {
            return true;
        }

        $map = $this->getMapClassToType();

        return isset($map[$className]);
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL type.
     *
     * @param string          $className The exact class name to look for (this function does not look into parent classes).
     * @param OutputType|null $subType   An optional sub-type if the main class is an iterator that needs to be typed.
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function mapClassToType(string $className, ?OutputType $subType): MutableObjectType
    {
        $typeClassName = $this->getTypeFromCacheByObjectClass($className);

        if ($typeClassName === null) {
            $map = $this->getMapClassToType();
            if (! isset($map[$className])) {
                throw CannotMapTypeException::createForType($className);
            }
            $typeClassName = $map[$className];
        }

        return $this->typeGenerator->mapAnnotatedObject($typeClassName);
    }

    /**
     * Returns the list of classes that have matching input GraphQL types.
     *
     * @return string[]
     */
    public function getSupportedClasses(): array
    {
        return array_keys($this->getMapClassToType());
    }

    /**
     * Returns true if this type mapper can map the $className FQCN to a GraphQL input type.
     */
    public function canMapClassToInputType(string $className): bool
    {
        $factory = $this->getFactoryFromCacheByObjectClass($className);

        if ($factory !== null) {
            return true;
        }
        $map = $this->getMapClassToFactory();

        return isset($map[$className]);
    }

    /**
     * Maps a PHP fully qualified class name to a GraphQL input type.
     *
     * @return ResolvableMutableInputInterface&InputObjectType
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function mapClassToInputType(string $className): ResolvableMutableInputInterface
    {
        $factory = $this->getFactoryFromCacheByObjectClass($className);

        if ($factory === null) {
            $map = $this->getMapClassToFactory();
            if (! isset($map[$className])) {
                throw CannotMapTypeException::createForInputType($className);
            }
            $factory = $map[$className];
        }

        return $this->inputTypeGenerator->mapFactoryMethod($factory[0], $factory[1], $this->container);
    }

    /**
     * Returns a GraphQL type by name (can be either an input or output type)
     *
     * @param string $typeName The name of the GraphQL type
     *
     * @return \GraphQL\Type\Definition\Type&((ResolvableMutableInputInterface&InputObjectType)|MutableObjectType)
     *
     * @throws CannotMapTypeExceptionInterface
     * @throws ReflectionException
     */
    public function mapNameToType(string $typeName): \GraphQL\Type\Definition\Type
    {
        $typeClassName = $this->getTypeFromCacheByGraphQLTypeName($typeName);
        if ($typeClassName === null) {
            $factory = $this->getFactoryFromCacheByGraphQLInputTypeName($typeName);
            if ($factory === null) {
                $mapNameToType = $this->getMapNameToType();
                if (isset($mapNameToType[$typeName])) {
                    $typeClassName = $mapNameToType[$typeName];
                } else {
                    $mapInputNameToFactory = $this->getMapInputNameToFactory();
                    if (isset($mapInputNameToFactory[$typeName])) {
                        $factory = $mapInputNameToFactory[$typeName];
                    }
                }
            }
        }

        if (isset($typeClassName)) {
            return $this->typeGenerator->mapAnnotatedObject($typeClassName);
        }
        if (isset($factory)) {
            return $this->inputTypeGenerator->mapFactoryMethod($factory[0], $factory[1], $this->container);
        }

        throw CannotMapTypeException::createForName($typeName);
    }

    /**
     * Returns true if this type mapper can map the $typeName GraphQL name to a GraphQL type.
     *
     * @param string $typeName The name of the GraphQL type
     */
    public function canMapNameToType(string $typeName): bool
    {
        $typeClassName = $this->getTypeFromCacheByGraphQLTypeName($typeName);

        if ($typeClassName !== null) {
            return true;
        }

        $factory = $this->getFactoryFromCacheByGraphQLInputTypeName($typeName);
        if ($factory !== null) {
            return true;
        }

        $this->getMaps();

        return isset($this->mapNameToType[$typeName]) || isset($this->mapInputNameToFactory[$typeName]);
    }

    /**
     * Returns true if this type mapper can extend an existing type for the $className FQCN
     */
    public function canExtendTypeForClass(string $className, MutableObjectType $type): bool
    {
        $extendTypeClassName = $this->getExtendTypesFromCacheByObjectClass($className);

        if ($extendTypeClassName === null) {
            $map = $this->getMapClassToExtendTypeArray();
        }

        return isset($this->mapClassToExtendTypeArray[$className]);
    }

    /**
     * Extends the existing GraphQL type that is mapped to $className.
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function extendTypeForClass(string $className, MutableObjectType $type): void
    {
        $extendTypeClassNames = $this->getExtendTypesFromCacheByObjectClass($className);

        if ($extendTypeClassNames === null) {
            $this->getMapClassToExtendTypeArray();
        }

        if (! isset($this->mapClassToExtendTypeArray[$className])) {
            throw CannotMapTypeException::createForExtendType($className, $type);
        }

        foreach ($this->mapClassToExtendTypeArray[$className] as $extendedTypeClass) {
            $this->typeGenerator->extendAnnotatedObject($this->container->get($extendedTypeClass), $type);
        }
    }

    /**
     * Returns true if this type mapper can extend an existing type for the $typeName GraphQL type
     */
    public function canExtendTypeForName(string $typeName, MutableObjectType $type): bool
    {
        $typeClassNames = $this->getExtendTypesFromCacheByGraphQLTypeName($typeName);

        if ($typeClassNames !== null) {
            return true;
        }

        /*$factory = $this->getFactoryFromCacheByGraphQLInputTypeName($typeName);
        if ($factory !== null) {
            return true;
        }*/

        $map = $this->getMapNameToExtendType();

        return isset($map[$typeName]);/* || isset($this->mapInputNameToFactory[$typeName])*/
    }

    /**
     * Extends the existing GraphQL type that is mapped to the $typeName GraphQL type.
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function extendTypeForName(string $typeName, MutableObjectType $type): void
    {
        $extendTypeClassNames = $this->getExtendTypesFromCacheByGraphQLTypeName($typeName);
        if ($extendTypeClassNames === null) {
            /*$factory = $this->getFactoryFromCacheByGraphQLInputTypeName($typeName);
            if ($factory === null) {*/
                $map = $this->getMapNameToExtendType();
            if (! isset($map[$typeName])) {
                throw CannotMapTypeException::createForExtendName($typeName, $type);
            }
                $extendTypeClassNames = $map[$typeName];

            //}
        }

        foreach ($extendTypeClassNames as $extendedTypeClass) {
            $this->typeGenerator->extendAnnotatedObject($this->container->get($extendedTypeClass), $type);
        }

        /*if (isset($this->mapInputNameToFactory[$typeName])) {
            $factory = $this->mapInputNameToFactory[$typeName];
            return $this->inputTypeGenerator->mapFactoryMethod($this->container->get($factory[0]), $factory[1], $recursiveTypeMapper);
        }*/
    }

    /**
     * Returns true if this type mapper can decorate an existing input type for the $typeName GraphQL input type
     */
    public function canDecorateInputTypeForName(string $typeName, ResolvableMutableInputInterface $type): bool
    {
        $typeClassNames = $this->getDecorateFromCacheByGraphQLInputTypeName($typeName);

        if ($typeClassNames !== null) {
            return true;
        }

        $map = $this->getMapInputNameToDecorator();

        return isset($map[$typeName]);
    }

    /**
     * Decorates the existing GraphQL input type that is mapped to the $typeName GraphQL input type.
     *
     * @param ResolvableMutableInputInterface &InputObjectType $type
     *
     * @throws CannotMapTypeExceptionInterface
     */
    public function decorateInputTypeForName(string $typeName, ResolvableMutableInputInterface $type): void
    {
        $decorators = $this->getDecorateFromCacheByGraphQLInputTypeName($typeName);
        if ($decorators === null) {
            $map = $this->getMapInputNameToDecorator();
            if (! isset($map[$typeName])) {
                throw CannotMapTypeException::createForDecorateName($typeName, $type);
            }
            $decorators = $map[$typeName];
        }

        foreach ($decorators as $decorator) {
            $this->inputTypeGenerator->decorateInputType($decorator[0], $decorator[1], $type, $this->container);
        }
    }
}
