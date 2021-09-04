<?php

namespace Spatie\RouteAttributes;

use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use Spatie\RouteAttributes\Attributes\Route;
use Spatie\RouteAttributes\Attributes\RouteAttribute;
use Spatie\RouteAttributes\Attributes\Where;
use Spatie\RouteAttributes\Attributes\WhereAttribute;
use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Throwable;

class RouteRegistrar
{
    private Router $router;

    protected string $basePath;

    protected string $rootNamespace;

    protected array $middleware = [];

    public function __construct(Router $router)
    {
        $this->router = $router;

        $this->basePath = app()->path();
    }

    public function useBasePath(string $basePath): self
    {
        $this->basePath = $basePath;

        return $this;
    }

    public function useRootNamespace(string $rootNamespace): self
    {
        $this->rootNamespace = $rootNamespace;

        return $this;
    }

    public function useMiddleware(string|array $middleware): self
    {
        $this->middleware = Arr::wrap($middleware);

        return $this;
    }

    public function middleware(): array
    {
        return $this->middleware ?? [];
    }

    public function registerDirectory(string|array $directories): void
    {
        $directories = Arr::wrap($directories);

        $files = (new Finder())->files()->name('*.php')->in($directories);

        collect($files)->each(fn(SplFileInfo $file) => $this->registerFile($file));
    }

    public function registerFile(string|SplFileInfo $path): void
    {
        if (is_string($path)) {
            $path = new SplFileInfo($path);
        }

        $fullyQualifiedClassName = $this->fullQualifiedClassNameFromFile($path);

        $this->processAttributes($fullyQualifiedClassName);
    }

    public function registerClass(string $class): void
    {
        $this->processAttributes($class);
    }

    protected function fullQualifiedClassNameFromFile(SplFileInfo $file): string
    {
        $isNamespacedFile = !empty($nsFile = $this->namespacedPath($file->getRealPath()));

        $class = trim(
            Str::replaceFirst(
                $isNamespacedFile ? $nsFile['path'] : $this->basePath,
                $isNamespacedFile ? str_replace('\\', DIRECTORY_SEPARATOR, $nsFile['namespace']) : '',
                $file->getRealPath()),
            DIRECTORY_SEPARATOR
        );

        $class = str_replace(
            [DIRECTORY_SEPARATOR, 'App\\'],
            ['\\', app()->getNamespace()],
            ucfirst(Str::replaceLast('.php', '', $class))
        );

        return ($isNamespacedFile) ? $class : $this->rootNamespace . $class;
    }

    protected function namespacedPath(string $fileName): ?array
    {
        /** @var array $namespacedDirs */
        $namespacedDirs = config('route-attributes.directory_namespaces', []);

        foreach ($namespacedDirs as $directory => $namespace) {
            if (str_contains($fileName, realpath($directory))) {
                return [
                    'path' => realpath($directory),
                    'namespace' => $namespace
                ];
            }
        }

        return null;
    }

    protected function processAttributes(string $className): void
    {
        if (!class_exists($className)) {
            return;
        }

        $class = new ReflectionClass($className);

        $classRouteAttributes = new ClassRouteAttributes($class);

        if ($classRouteAttributes->resource()) {
            $this->registerResource($class, $classRouteAttributes);
        }

        ($prefix = $classRouteAttributes->prefix())
            ? $this->router->prefix($prefix)->group(fn() => $this->registerRoutes($class, $classRouteAttributes))
            : $this->registerRoutes($class, $classRouteAttributes);
    }

    protected function registerResource(ReflectionClass $class, ClassRouteAttributes $classRouteAttributes): void
    {
        $this->router->group([
            'domain' => $classRouteAttributes->domain(),
            'prefix' => $classRouteAttributes->prefix(),
        ], function () use ($class, $classRouteAttributes) {
            $route = $classRouteAttributes->apiResource()
                ? $this->router->apiResource($classRouteAttributes->resource(), $class->getName())
                : $this->router->resource($classRouteAttributes->resource(), $class->getName());

            if ($only = $classRouteAttributes->only()) {
                $route->only($only);
            }

            if ($except = $classRouteAttributes->except()) {
                $route->except($except);
            }

            if ($middleware = $classRouteAttributes->middleware()) {
                $route->middleware([...$this->middleware, ...$middleware]);
            }
        });
    }

    protected function registerRoutes(ReflectionClass $class, ClassRouteAttributes $classRouteAttributes): void
    {
        foreach ($class->getMethods() as $method) {
            $attributes = $method->getAttributes(RouteAttribute::class, ReflectionAttribute::IS_INSTANCEOF);
            $wheresAttributes = $method->getAttributes(WhereAttribute::class, ReflectionAttribute::IS_INSTANCEOF);

            foreach ($attributes as $attribute) {
                try {
                    $attributeClass = $attribute->newInstance();
                } catch (Throwable) {
                    continue;
                }

                if (!$attributeClass instanceof Route) {
                    continue;
                }

                $httpMethods = $attributeClass->methods;

                $action = $method->getName() === '__invoke'
                    ? $class->getName()
                    : [$class->getName(), $method->getName()];

                if ($domain = $classRouteAttributes->domainFromConfig() ?? $classRouteAttributes->domain()) {
                    $route = $this->router->domain($domain)
                        ->name($attributeClass->name)
                        ->match($httpMethods, $attributeClass->uri, $action);
                } else {
                    $route = $this->router->addRoute($httpMethods, $attributeClass->uri, $action)
                        ->name($attributeClass->name);
                }

                $wheres = $classRouteAttributes->wheres();
                foreach ($wheresAttributes as $wheresAttribute) {
                    /** @var Where $wheresAttributeClass */
                    $wheresAttributeClass = $wheresAttribute->newInstance();

                    // This also overrides class wheres if the same param is used
                    $wheres[$wheresAttributeClass->param] = $wheresAttributeClass->constraint;
                }
                if (!empty($wheres)) {
                    $route->setWheres($wheres);
                }

                $classMiddleware = $classRouteAttributes->middleware();
                $methodMiddleware = $attributeClass->middleware;
                $route->middleware([...$this->middleware, ...$classMiddleware, ...$methodMiddleware]);
            }
        }
    }
}
