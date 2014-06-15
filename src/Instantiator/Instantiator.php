<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace Instantiator;

use Closure;
use LazyMap\CallbackLazyMap;
use ReflectionClass;

/**
 * {@inheritDoc}
 *
 * @author Marco Pivetta <ocramius@gmail.com>
 */
final class Instantiator implements InstantiatorInterface
{
    /**
     * @var CallbackLazyMap of {@see \Closure} instances
     */
    private $cachedInstantiators;

    /**
     * @var CallbackLazyMap of objects that can directly be cloned
     */
    private $cachedCloneables;

    public function __construct()
    {
        $that = $this;

        $this->cachedInstantiators = new CallbackLazyMap(function ($className) use ($that) {
            return $that->buildFactory($className);
        });

        $cachedInstantiators = $this->cachedInstantiators;

        $this->cachedCloneables = new CallbackLazyMap(function ($className) use ($that, $cachedInstantiators) {
            $reflection = new ReflectionClass($className);

            if ($reflection->hasMethod('__clone')) {
                return null;
            }

            /* @var $factory Closure */
            $factory = $cachedInstantiators->$className;

            return $factory();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function instantiate($className)
    {
        if ($cloneable = $this->cachedCloneables->$className) {
            return clone $cloneable;
        }

        $factory = $this->cachedInstantiators->$className;

        /* @var $factory Closure */
        return $factory();
    }

    /**
     * @param string $className
     *
     * @return Closure
     */
    public function buildFactory($className)
    {
        $reflectionClass = new ReflectionClass($className);

        if (\PHP_VERSION_ID >= 50400 && ! $this->hasInternalAncestors($reflectionClass)) {
            return function () use ($reflectionClass) {
                return $reflectionClass->newInstanceWithoutConstructor();
            };
        }

        $serializedString = sprintf(
            '%s:%d:"%s":0:{}',
            $this->getSerializationFormat($reflectionClass),
            strlen($className),
            $className
        );

        return function () use ($serializedString) {
            return unserialize($serializedString);
        };
    }

    /**
     * Verifies whether the given class is to be considered internal
     *
     * @param ReflectionClass $reflectionClass
     *
     * @return bool
     */
    private function hasInternalAncestors(ReflectionClass $reflectionClass)
    {
        do {
            if ($reflectionClass->isInternal()) {
                return true;
            }
        } while ($reflectionClass = $reflectionClass->getParentClass());

        return false;
    }

    /**
     * Verifies if the given PHP version implements the `Serializable` interface serialization
     * with an incompatible serialization format. If that's the case, use serialization marker
     * "C" instead of "O".
     *
     * @link http://news.php.net/php.internals/74654
     *
     * @param ReflectionClass $reflectionClass
     *
     * @return string the serialization format marker, either "O" or "C"
     */
    private function getSerializationFormat(ReflectionClass $reflectionClass)
    {
        if ($this->isPhpVersionWithBrokenSerializationFormat()
            && $reflectionClass->implementsInterface('Serializable')
        ) {
            return 'C';
        }

        return 'O';
    }

    /**
     * Checks whether the current PHP runtime uses an incompatible serialization format
     *
     * @return bool
     */
    private function isPhpVersionWithBrokenSerializationFormat()
    {
        return PHP_VERSION_ID === 50429 || PHP_VERSION_ID === 50513 || PHP_VERSION_ID === 50600;
    }
}
