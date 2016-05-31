<?php

namespace Codeception\Module;


use Codeception\Exception\ModuleException;
use Codeception\Lib\ModuleContainer;
use Codeception\Module;
use Codeception\TestCase;
use tad\WPBrowser\Environment\Constants;
use tad\WPBrowser\Iterators\Filters\ActionsQueriesFilter;
use tad\WPBrowser\Iterators\Filters\ClassMethodQueriesFilter;
use tad\WPBrowser\Iterators\Filters\FactoryQueriesFilter;
use tad\WPBrowser\Iterators\Filters\FiltersQueriesFilter;
use tad\WPBrowser\Iterators\Filters\FunctionQueriesFilter;
use tad\WPBrowser\Iterators\Filters\MainStatementQueriesFilter;
use tad\WPBrowser\Iterators\Filters\SetupTearDownQueriesFilter;

class WPQueries extends Module
{
    /**
     * @var array
     */
    protected $filteredQueries = [];

    /**
     * @var callable[]
     */
    protected $assertions = [];

    /**
     * @var Constants
     */
    private $constants;

    /**
     * WPQueries constructor.
     *
     * @param ModuleContainer $moduleContainer
     * @param null $config
     * @param Constants|null $constants
     */
    public function __construct(ModuleContainer $moduleContainer, $config, Constants $constants = null)
    {
        $this->constants = $constants ? $constants : new Constants();
        parent::__construct($moduleContainer, $config);
    }

    public function _initialize()
    {
        if (!($this->moduleContainer->hasModule('WPLoader') || $this->moduleContainer->hasModule('WPBootstrapper'))) {
            throw new ModuleException(__CLASS__, "Modules WPLoader or WPBootstrapper are required for WPQueries to work");
        }

        $this->constants->defineIfUndefined('SAVEQUERIES', true);
    }

    /**
     * Runs before each test method.
     */
    public function _cleanup()
    {
        /** @var \wpdb $wpdb */
        global $wpdb;
        $wpdb->queries = [];
    }

    /**
     * Asserts that at least one query was made during the test.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $message
     */
    public function assertQueries($message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that queries were made.';
        \PHPUnit_Framework_Assert::assertNotEmpty($this->filteredQueries, $message);
    }

    private function readQueries()
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        if (empty($wpdb->queries)) {
            $this->filteredQueries = [];
        } else {
            $filteredQueriesIterator = $this->_getFilteredQueriesIterator();
            $this->filteredQueries = iterator_to_array($filteredQueriesIterator);
        }

    }

    /**
     * Returns the saved queries after filtering.
     *
     * @param \wpdb $wpdb
     * @return \FilterIterator
     */
    public function _getFilteredQueriesIterator(\wpdb $wpdb = null)
    {
        if (null === $wpdb) {
            /** @var \wpdb $wpdb */
            global $wpdb;
        }

        $queriesArrayIterator = new \ArrayIterator($wpdb->queries);
        $filteredQueriesIterator = new SetupTearDownQueriesFilter(new FactoryQueriesFilter($queriesArrayIterator));

        return $filteredQueriesIterator;
    }

    /**
     * Asserts that no queries were made.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $message
     */
    public function assertNotQueries($message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that no queries were made.';
        \PHPUnit_Framework_Assert::assertEmpty($this->filteredQueries, $message);
    }

    /**
     * Asserts that n queries have been made.
     *
     * @param int $n
     * @param string $message
     */
    public function assertCountQueries($n, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries were made.';
        \PHPUnit_Framework_Assert::assertCount($n, $this->filteredQueries, $message);
    }

    /**
     * Asserts that at least a query starting with the specified statement was made.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $statement
     * @param string $message
     */
    public function assertQueriesByStatement($statement, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that queries beginning with statement [' . $statement . '] were made.';
        $statementIterator = new MainStatementQueriesFilter(new \ArrayIterator($this->filteredQueries), $statement);
        \PHPUnit_Framework_Assert::assertNotEmpty(iterator_to_array($statementIterator), $message);
    }

    public function assertQueriesByMethod($class, $method, $message = '')
    {
        $this->readQueries();
        $class = ltrim($class, '\\');
        $message = $message ? $message : 'Failed asserting that queries were made by method [' . $class . '::' . $method . ']';
        $statementIterator = new ClassMethodQueriesFilter(new \ArrayIterator($this->filteredQueries), $class, $method);
        \PHPUnit_Framework_Assert::assertNotEmpty(iterator_to_array($statementIterator), $message);
    }

    public function assertNotQueriesByStatement($statement, $message = '')
    {
        $message = $message ? $message : 'Failed asserting that no queries beginning with statement [' . $statement . '] were made.';
        $this->assertQueriesCountByStatement(0, $statement, $message);
    }

    /**
     * Asserts that n queries starting with the specified statement were made.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param int $n
     * @param string $statement
     * @param string $message
     */
    public function assertQueriesCountByStatement($n, $statement, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries beginning with statement [' . $statement . '] were made.';
        $statementIterator = new MainStatementQueriesFilter(new \ArrayIterator($this->filteredQueries), $statement);
        \PHPUnit_Framework_Assert::assertCount($n, iterator_to_array($statementIterator), $message);
    }

    /**
     * Asserts that no queries have been made by the specified class method.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param $class
     * @param $method
     * @param string $message
     */
    public function assertNotQueriesByMethod($class, $method, $message = '')
    {
        $message = $message ? $message : 'Failed asserting that no queries were made by method [' . $class . '::' . $method . ']';
        $this->assertQueriesCountByMethod(0, $class, $method, $message);
    }

    /**
     * Asserts that n queries have been made by the specified class method.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param int $n
     * @param string $class
     * @param string $method
     * @param string $message
     */
    public function assertQueriesCountByMethod($n, $class, $method, $message = '')
    {
        $this->readQueries();
        $class = ltrim($class, '\\');
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries were made by method [' . $class . '::' . $method . ']';
        $statementIterator = new ClassMethodQueriesFilter(new \ArrayIterator($this->filteredQueries), $class, $method);
        \PHPUnit_Framework_Assert::assertCount($n, iterator_to_array($statementIterator), $message);
    }

    /**
     * Asserts that queries were made by the specified function.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $function
     * @param string $message
     */
    public function assertQueriesByFunction($function, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that queries were made by function [' . $function . ']';
        $statementIterator = new FunctionQueriesFilter(new \ArrayIterator($this->filteredQueries), $function);
        \PHPUnit_Framework_Assert::assertNotEmpty(iterator_to_array($statementIterator), $message);
    }

    /**
     * Asserts that no queries were made by the specified function.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $function
     * @param string $message
     */
    public function assertNotQueriesByFunction($function, $message = '')
    {
        $message = $message ? $message : 'Failed asserting that no queries were made by function [' . $function . ']';
        $this->assertQueriesCountByFunction(0, $function, $message);
    }

    /**
     * Asserts that n queries were made by the specified function.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param int $n
     * @param string $function
     * @param string $message
     */
    public function assertQueriesCountByFunction($n, $function, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries were made by function [' . $function . ']';
        $statementIterator = new FunctionQueriesFilter(new \ArrayIterator($this->filteredQueries), $function);
        \PHPUnit_Framework_Assert::assertCount($n, iterator_to_array($statementIterator), $message);
    }

    /**
     * Asserts that queries were made by the specified class method starting with the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $statement
     * @param string $class
     * @param string $method
     * @param string $message
     */
    public function assertQueriesByStatementAndMethod($statement, $class, $method, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that queries were made by method [' . $class . '::' . $method . '] containing statement [' . $statement . ']';
        $statementIterator = new MainStatementQueriesFilter(new ClassMethodQueriesFilter(new \ArrayIterator($this->filteredQueries), $class, $method), $statement);
        \PHPUnit_Framework_Assert::assertNotEmpty(iterator_to_array($statementIterator), $message);
    }

    /**
     * Asserts that no queries were made by the specified class method starting with the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $statement
     * @param string $class
     * @param string $method
     * @param string $message
     */
    public function assertNotQueriesByStatementAndMethod($statement, $class, $method, $message = '')
    {
        $message = $message ? $message : 'Failed asserting that no queries were made by method [' . $class . '::' . $method . '] containing statement [' . $statement . ']';
        $this->assertQueriesCountByStatementAndMethod(0, $statement, $class, $method, $message);
    }

    /**
     * Asserts that n queries were made by the specified class method starting with the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param int $n
     * @param string $statement
     * @param string $class
     * @param string $method
     * @param string $message
     */
    public function assertQueriesCountByStatementAndMethod($n, $statement, $class, $method, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries were made by method [' . $class . '::' . $method . '] containing statement [' . $statement . ']';
        $statementIterator = new MainStatementQueriesFilter(new ClassMethodQueriesFilter(new \ArrayIterator($this->filteredQueries), $class, $method), $statement);
        \PHPUnit_Framework_Assert::assertCount($n, iterator_to_array($statementIterator), $message);
    }

    /**
     * Asserts that queries were made by the specified function starting with the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $statement
     * @param string $function
     * @param string $message
     */
    public function assertQueriesByStatementAndFunction($statement, $function, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that queries were made by function [' . $function . '] containing statement [' . $statement . ']';
        $statementIterator = new MainStatementQueriesFilter(new FunctionQueriesFilter(new \ArrayIterator($this->filteredQueries), $function), $statement);
        \PHPUnit_Framework_Assert::assertNotEmpty(iterator_to_array($statementIterator), $message);
    }

    /**
     * Asserts that no queries were made by the specified function starting with the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $statement
     * @param string $function
     * @param string $message
     */
    public function assertNotQueriesByStatementAndFunction($statement, $function, $message = '')
    {
        $message = $message ? $message : 'Failed asserting that no queries were made by function [' . $function . '] containing statement [' . $statement . ']';
        $this->assertQueriesCountByStatementAndFunction(0, $statement, $function, $message);
    }

    /**
     * Asserts that n queries were made by the specified function starting with the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param int $n
     * @param string $statement
     * @param string $function
     * @param string $message
     */
    public function assertQueriesCountByStatementAndFunction($n, $statement, $function, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries were made by method [' . $function . '] containing statement [' . $statement . ']';
        $statementIterator = new MainStatementQueriesFilter(new FunctionQueriesFilter(new \ArrayIterator($this->filteredQueries), $function), $statement);
        \PHPUnit_Framework_Assert::assertCount($n, iterator_to_array($statementIterator), $message);
    }

    /**
     * Asserts that at least one query was made as a consequence of the specified action.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $action
     * @param string $message
     */
    public function assertQueriesByAction($action, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that queries were triggered by action [' . $action . ']';
        $iterator = new ActionsQueriesFilter(new \ArrayIterator($this->filteredQueries), $action);
        \PHPUnit_Framework_Assert::assertNotEmpty(iterator_to_array($iterator), $message);
    }

    /**
     * Asserts that no queries were made as a consequence of the specified action.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $action
     * @param string $message
     */
    public function assertNotQueriesByAction($action, $message = '')
    {
        $message = $message ? $message : 'Failed asserting that no queries were triggered by action [' . $action . ']';
        $this->assertQueriesCountByAction(0, $action, $message);
    }

    /**
     * Asserts that n queries were made as a consequence of the specified action.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param int $n
     * @param string $action
     * @param string $message
     */
    public function assertQueriesCountByAction($n, $action, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries were triggered by action [' . $action . ']';
        $iterator = new ActionsQueriesFilter(new \ArrayIterator($this->filteredQueries), $action);
        \PHPUnit_Framework_Assert::assertCount($n, iterator_to_array($iterator), $message);
    }

    /**
     * Asserts that at least one query was made as a consequence of the specified action containing the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $statement
     * @param string $action
     * @param string $message
     */
    public function assertQueriesByStatementAndAction($statement, $action, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that queries were triggered by action  [' . $action . '] containing statement [' . $statement . ']';
        $iterator = new MainStatementQueriesFilter(new ActionsQueriesFilter(new \ArrayIterator($this->filteredQueries), $action), $statement);
        \PHPUnit_Framework_Assert::assertNotEmpty(iterator_to_array($iterator), $message);
    }

    /**
     * Asserts that no queries were made as a consequence of the specified action containing the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $statement
     * @param string $action
     * @param string $message
     */
    public function assertNotQueriesByStatementAndAction($statement, $action, $message = '')
    {
        $message = $message ? $message : 'Failed asserting that no queries were triggered by action  [' . $action . '] containing statement [' . $statement . ']';
        $this->assertQueriesCountByStatementAndAction(0, $statement, $action, $message);
    }

    /**
     * Asserts that n queries were made as a consequence of the specified action containing the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param int $n
     * @param string $statement
     * @param string $action
     * @param string $message
     */
    public function assertQueriesCountByStatementAndAction($n, $statement, $action, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries were triggered by action  [' . $action . '] containing statement [' . $statement . ']';
        $iterator = new MainStatementQueriesFilter(new ActionsQueriesFilter(new \ArrayIterator($this->filteredQueries), $action), $statement);
        \PHPUnit_Framework_Assert::assertCount($n, iterator_to_array($iterator), $message);
    }

    /**
     * Asserts that at least one query was made as a consequence of the specified filter.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $filter
     * @param string $message
     */
    public function assertQueriesByFilter($filter, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that queries were triggered by filter [' . $filter . ']';
        $iterator = new FiltersQueriesFilter(new \ArrayIterator($this->filteredQueries), $filter);
        \PHPUnit_Framework_Assert::assertNotEmpty(iterator_to_array($iterator), $message);
    }

    /**
     * Asserts that no queries were made as a consequence of the specified filter.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $filter
     * @param string $message
     */
    public function assertNotQueriesByFilter($filter, $message = '')
    {
        $message = $message ? $message : 'Failed asserting that no queries were triggered by filter [' . $filter . ']';
        $this->assertQueriesCountByFilter(0, $filter, $message);
    }

    /**
     * Asserts that n queries were made as a consequence of the specified filter.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param int $n
     * @param string $filter
     * @param string $message
     */
    public function assertQueriesCountByFilter($n, $filter, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries were triggered by filter [' . $filter . ']';
        $iterator = new FiltersQueriesFilter(new \ArrayIterator($this->filteredQueries), $filter);
        \PHPUnit_Framework_Assert::assertCount($n, iterator_to_array($iterator), $message);
    }

    /**
     * Asserts that at least one query was made as a consequence of the specified filter containing the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $statement
     * @param string $filter
     * @param string $message
     */
    public function assertQueriesByStatementAndFilter($statement, $filter, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that queries were triggered by filter  [' . $filter . '] containing statement [' . $statement . ']';
        $iterator = new MainStatementQueriesFilter(new FiltersQueriesFilter(new \ArrayIterator($this->filteredQueries), $filter), $statement);
        \PHPUnit_Framework_Assert::assertNotEmpty(iterator_to_array($iterator), $message);
    }

    /**
     * Asserts that no queries were made as a consequence of the specified filter containing the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param string $statement
     * @param string $filter
     * @param string $message
     */
    public function assertNotQueriesByStatementAndFilter($statement, $filter, $message = '')
    {
        $message = $message ? $message : 'Failed asserting that no queries were triggered by filter  [' . $filter . '] containing statement [' . $statement . ']';
        $this->assertQueriesCountByStatementAndFilter(0, $statement, $filter, $message);
    }

    /**
     * Asserts that n queries were made as a consequence of the specified filter containing the specified SQL statement.
     *
     * Queries generated by setUp, tearDown and factory methods are excluded by default.
     *
     * @param int $n
     * @param string $statement
     * @param string $filter
     * @param string $message
     */
    public function assertQueriesCountByStatementAndFilter($n, $statement, $filter, $message = '')
    {
        $this->readQueries();
        $message = $message ? $message : 'Failed asserting that ' . $n . ' queries were triggered by filter  [' . $filter . '] containing statement [' . $statement . ']';
        $iterator = new MainStatementQueriesFilter(new FiltersQueriesFilter(new \ArrayIterator($this->filteredQueries), $filter), $statement);
        \PHPUnit_Framework_Assert::assertCount($n, iterator_to_array($iterator), $message);
    }
}
