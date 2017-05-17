<?php

namespace Kaliop\eZMigrationBundle\Core\Executor;

use eZ\Publish\API\Repository\Repository;
use Kaliop\eZMigrationBundle\API\ReferenceResolverBagInterface;
use Kaliop\eZMigrationBundle\API\Value\MigrationStep;
use Kaliop\eZMigrationBundle\Core\RepositoryUserSetterTrait;

/**
 * The core manager class that all migration action managers inherit from.
 */
abstract class RepositoryExecutor extends AbstractExecutor
{
    use RepositoryUserSetterTrait;

    /**
     * Constant defining the default language code (used if not specified by the migration or on the command line)
     */
    const DEFAULT_LANGUAGE_CODE = 'eng-GB';

    /**
     * The default Admin user Id, used when no Admin user is specified
     */
    const ADMIN_USER_ID = 14;

    /** Used if not specified by the migration */
    const USER_CONTENT_TYPE = 'user';

    /**
     * @var array $dsl The parsed DSL instruction array
     */
    //protected $dsl;

    /** @var array $context The context (configuration) for the execution of the current step */
    //protected $context;

    /**
     * The eZ Publish 5 API repository.
     *
     * @var \eZ\Publish\API\Repository\Repository
     */
    protected $repository;

    /**
     * Language code for current step.
     *
     * @var string
     */
    //private $languageCode;

    /**
     * @var string
     */
    //private $defaultLanguageCode;

    /** @var ReferenceResolverBagInterface $referenceResolver */
    protected $referenceResolver;

    // to redefine in subclasses if they don't support all methods, or if they support more...
    protected $supportedActions = array(
        'create', 'update', 'delete'
    );

    public function setRepository(Repository $repository)
    {
        $this->repository = $repository;
    }

    public function setReferenceResolver(ReferenceResolverBagInterface $referenceResolver)
    {
        $this->referenceResolver = $referenceResolver;
    }

    public function execute(MigrationStep $step)
    {
        // base checks
        parent::execute($step);

        if (!isset($step->dsl['mode'])) {
            throw new \Exception("Invalid step definition: missing 'mode'");
        }

        $action = $step->dsl['mode'];

        if (!in_array($action, $this->supportedActions)) {
            throw new \Exception("Invalid step definition: value '$action' is not allowed for 'mode'");
        }

        if (method_exists($this, $action)) {

            $previousUserId = $this->loginUser($this->getAdminUserIdentifierFromContext($step->context));

            try {
                $output = $this->$action($step);
            } catch (\Exception $e) {
                $this->loginUser($previousUserId);
                throw $e;
            }

            // reset the environment as much as possible as we had found it before the migration
            $this->loginUser($previousUserId);

            return $output;
        } else {
            throw new \Exception("Invalid step definition: value '$action' is not a method of " . get_class($this));
        }
    }

    /**
     * Method that each executor (subclass) has to implement.
     *
     * It is used to set references based on the DSL instructions executed in the current step, for later steps to reuse.
     *
     * @throws \InvalidArgumentException when trying to set a reference to an unsupported attribute.
     * @param $object
     * @return boolean
     */
    abstract protected function setReferences($object, $step);

    /**
     * @param MigrationStep $step
     * @return string
     */
    protected function getLanguageCode($step)
    {
        return isset($step->dsl['lang']) ? $step->dsl['lang'] : $this->getLanguageCodeFromContext($step->context);
    }

    /**
     * @param array $context
     * @return string
     */
    protected function getLanguageCodeFromContext($context)
    {
        return isset($context['defaultLanguageCode']) ? $context['defaultLanguageCode'] : self::DEFAULT_LANGUAGE_CODE;
    }

    /**
     * @param MigrationStep $step
     * @return string
     */
    protected function getUserContentType($step)
    {
        return isset($step->dsl['user_content_type']) ? $step->dsl['user_content_type'] : $this->getUserContentTypeFromContext($step->context);
    }

    protected function getUserContentTypeFromContext($context)
    {
        return isset($context['userContentType']) ? $context['userContentType'] : self::USER_CONTENT_TYPE;
    }

    /**
     * @param $context we have to return FALSE is it is set as adminUserLogin, whereas if NULL is set, we return the default admin
     * @return int|string|false
     */
    protected function getAdminUserIdentifierFromContext($context)
    {
        if (isset($context['adminUserLogin'])) {
            return $context['adminUserLogin'];
        }

        return self::ADMIN_USER_ID;
    }

    /**
     * Courtesy code to avoid reimplementing it in every subclass
     * @deprecated will be moved into the reference resolver classes
     */
    protected function resolveReferencesRecursively($match)
    {
        if (is_array($match)) {
            foreach ($match as $condition => $values) {
                $match[$condition] = $this->resolveReferencesRecursively($values);
            }
            return $match;
        } else {
            return $this->referenceResolver->resolveReference($match);
        }
    }
}
