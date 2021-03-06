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
 * and is licensed under the LGPL. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\ODM\PHPCR\Proxy;

use Doctrine\ODM\PHPCR\DocumentManager,
    Doctrine\ODM\PHPCR\Mapping\ClassMetadata;

/**
 * This factory is used to create proxy objects for entities at runtime.
 *
 * @author Roman Borschel <roman@code-factory.org>
 * @author Giorgio Sironi <piccoloprincipeazzurro@gmail.com>
 * @author Nils Adermann <naderman@naderman.de>
 * @author Johannes Stark <starkj@gmx.de>
 * @author David Buchmann <david@liip.ch>
 *
 * This whole thing is copy & pasted from ORM - should really be slightly
 * refactored to generate
 */
class ProxyFactory
{
    /** The DocumentManager this factory is bound to. */
    private $dm;
    /** Whether to automatically (re)generate proxy classes. */
    private $autoGenerate;
    /** The namespace that contains all proxy classes. */
    private $proxyNamespace;
    /** The directory that contains all proxy classes. */
    private $proxyDir;

    /**
     * Initializes a new instance of the <tt>ProxyFactory</tt> class that is
     * connected to the given <tt>DocumentManager</tt>.
     *
     * @param DocumentManager $dm The DocumentManager the new factory works for.
     * @param string $proxyDir The directory to use for the proxy classes. It must exist.
     * @param string $proxyNs The namespace to use for the proxy classes.
     * @param boolean $autoGenerate Whether to automatically generate proxy classes.
     */
    public function __construct(DocumentManager $dm, $proxyDir, $proxyNs, $autoGenerate = false)
    {
        if (!$proxyDir) {
            throw ProxyException::proxyDirectoryRequired();
        }
        if (!$proxyNs) {
            throw ProxyException::proxyNamespaceRequired();
        }
        $this->dm = $dm;
        $this->proxyDir = $proxyDir;
        $this->autoGenerate = $autoGenerate;
        $this->proxyNamespace = $proxyNs;
    }

    /**
     * Gets a reference proxy instance for the entity of the given type and identified by
     * the given identifier.
     *
     * @param string $className
     * @param mixed $identifier
     * @return object
     */
    public function getProxy($className, $identifier)
    {
        $proxyClassName = str_replace('\\', '', $className) . 'ReferenceProxy';
        $fqn = $this->proxyNamespace . '\\' . $proxyClassName;

        if ($this->autoGenerate && !class_exists($fqn, false)) {
            $fileName = $this->proxyDir . DIRECTORY_SEPARATOR . $proxyClassName . '.php';
            $this->generateProxyClass($this->dm->getClassMetadata($className), $proxyClassName, $fileName, self::$proxyClassTemplate);
            require $fileName;
        }

        if (!$this->dm->getMetadataFactory()->hasMetadataFor($fqn)) {
            $this->dm->getMetadataFactory()->setMetadataFor($fqn, $this->dm->getClassMetadata($className));
        }

        return new $fqn($this->dm, $identifier);
    }

    /**
     * Generates proxy classes for all given classes.
     *
     * @param array $classes The classes (ClassMetadata instances) for which to generate proxies.
     * @param string $toDir The target directory of the proxy classes. If not specified, the
     *                      directory configured on the Configuration of the DocumentManager used
     *                      by this factory is used.
     */
    public function generateProxyClasses(array $classes, $toDir = null)
    {
        $proxyDir = $toDir ?: $this->proxyDir;
        $proxyDir = rtrim($proxyDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        foreach ($classes as $class) {
            /* @var $class ClassMetadata */
            if ($class->isMappedSuperclass) {
                continue;
            }

            $proxyClassName = str_replace('\\', '', $class->name) . 'ReferenceProxy';
            $proxyFileName = $proxyDir . $proxyClassName . '.php';
            $this->generateProxyClass($class, $proxyClassName, $proxyFileName, self::$proxyClassTemplate);
        }
    }

    /**
     * Generates a proxy class file.
     *
     * @param $class
     * @param $originalClassName
     * @param $proxyClassName
     * @param $file The path of the file to write to.
     */
    private function generateProxyClass($class, $proxyClassName, $fileName, $file)
    {
        $methods = $this->generateMethods($class);
        $unsetAttributes = $this->getUnsetAttributes($class);
        $sleepImpl = $this->generateSleep($class);

        $placeholders = array(
            '<namespace>',
            '<proxyClassName>', '<className>',
            '<unsetattributes>',
            '<methods>', '<sleepImpl>'
        );

        if (substr($class->name, 0, 1) == "\\") {
            $className = substr($class->name, 1);
        } else {
            $className = $class->name;
        }

        $replacements = array(
            $this->proxyNamespace,
            $proxyClassName, $className,
            $unsetAttributes,
            $methods, $sleepImpl
        );

        $file = str_replace($placeholders, $replacements, $file);

        file_put_contents($fileName, $file, LOCK_EX);
    }

    /**
     * Get the attributes of the document class
     *
     * @param ClassMetadata $class
     * @return string The unset command for all document attributes
     */
    private function getUnsetAttributes(ClassMetadata $class)
    {
        $attributes = "";
        foreach ($class->fieldMappings as $field) {
            $attributes .= '$this->'.$field["fieldName"];
            $attributes .= ", ";
        }

        foreach ($class->associationsMappings as $field) {
            $attributes .= '$this->'.$field["fieldName"];
            $attributes .= ", ";
        }

        foreach ($class->referrersMappings as $field) {
            $attributes .= '$this->'.$field["fieldName"];
            $attributes .= ", ";
        }

        foreach ($class->childrenMappings as $field) {
            $attributes .= '$this->'.$field["fieldName"];
            $attributes .= ", ";
        }

        foreach ($class->childMappings as $field) {
            $attributes .= '$this->'.$field["fieldName"];
            $attributes .= ", ";
        }

        $attributes .= '$this->id';

        return "unset(".$attributes.");";
    }

    /**
     * Generates the methods of a proxy class.
     *
     * @param ClassMetadata $class
     * @return string The code of the generated methods.
     */
    private function generateMethods(ClassMetadata $class)
    {
        $methods = '';

        foreach ($class->reflClass->getMethods() as $method) {
            /* @var $method ReflectionMethod */
            if ($method->isConstructor() || strtolower($method->getName()) == "__sleep") {
                continue;
            }

            if ($method->isPublic() && !$method->isFinal() && !$method->isStatic()) {
                $methods .= PHP_EOL . '    public function ';
                if ($method->returnsReference()) {
                    $methods .= '&';
                }
                $methods .= $method->getName() . '(';
                $firstParam = true;
                $parameterString = $argumentString = '';

                foreach ($method->getParameters() as $param) {
                    if ($firstParam) {
                        $firstParam = false;
                    } else {
                        $parameterString .= ', ';
                        $argumentString  .= ', ';
                    }

                    // We need to pick the type hint class too
                    if (($paramClass = $param->getClass()) !== null) {
                        $parameterString .= '\\' . $paramClass->getName() . ' ';
                    } elseif ($param->isArray()) {
                        $parameterString .= 'array ';
                    }

                    if ($param->isPassedByReference()) {
                        $parameterString .= '&';
                    }

                    $parameterString .= '$' . $param->getName();
                    $argumentString  .= '$' . $param->getName();

                    if ($param->isDefaultValueAvailable()) {
                        $parameterString .= ' = ' . var_export($param->getDefaultValue(), true);
                    }
                }

                $methods .= $parameterString . ')';
                $methods .= PHP_EOL . '    {' . PHP_EOL;
                $methods .= '        $this->__load();' . PHP_EOL;
                $methods .= '        return parent::' . $method->getName() . '(' . $argumentString . ');';
                $methods .= PHP_EOL . '    }' . PHP_EOL;
            }
        }

        return $methods;
    }

    /**
     * Generates the code for the __sleep method for a proxy class.
     *
     * @param $class
     * @return string
     */
    private function generateSleep(ClassMetadata $class)
    {
        $sleepImpl = '';

        if ($class->reflClass->hasMethod('__sleep')) {
            $sleepImpl .= "return array_merge(array('__isInitialized__'), parent::__sleep());";
        } else {
            $sleepImpl .= "return array('__isInitialized__', ";

            $properties = array();
            foreach ($class->fieldMappings as $name => $prop) {
                $properties[] = "'$name'";
            }

            $sleepImpl .= implode(',', $properties) . ');';
        }

        return $sleepImpl;
    }

    /** Proxy class code template */
    private static $proxyClassTemplate = <<<'PHP'
<?php

namespace <namespace>;

/**
 * THIS CLASS WAS GENERATED BY THE DOCTRINE ORM. DO NOT EDIT THIS FILE.
 */
class <proxyClassName> extends \<className> implements \Doctrine\ODM\PHPCR\Proxy\Proxy
{
    private $__doctrineDocumentManager__;
    private $__doctrineIdentifier__;
    public $__isInitialized__ = false;
    public function __construct($documentManager, $identifier)
    {
        <unsetattributes>
        $this->__doctrineDocumentManager__ = $documentManager;
    }

    public function __load()
    {
        if (!$this->__isInitialized__ && $this->__doctrineDocumentManager__) {
            $this->__isInitialized__ = true;
            $this->__doctrineDocumentManager__->getRepository(get_class($this))->refreshDocumentForProxy($this);
            unset($this->__doctrineDocumentManager__);
        }
    }

    <methods>

    public function __sleep()
    {
        <sleepImpl>
    }

    public function __set($name, $value)
    {
        $this->__load();
        $this->$name = $value;
    }

    public function &__get($name)
    {
        $this->__load();
        return $this->$name;
    }

}
PHP;
}

