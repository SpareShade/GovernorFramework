<?php

namespace Governor\Tests\Plugin\SymfonyBundle;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Governor\Framework\Plugin\SymfonyBundle\DependencyInjection\GovernorFrameworkExtension;
use Governor\Framework\Plugin\SymfonyBundle\DependencyInjection\Compiler\CommandHandlerPass;
use Governor\Framework\Plugin\SymfonyBundle\DependencyInjection\Compiler\EventHandlerPass;
use Governor\Framework\Repository\RepositoryInterface;
use Governor\Framework\Annotations\EventHandler;
use Governor\Framework\Annotations\CommandHandler;
use Symfony\Bridge\ProxyManager\LazyProxy\Instantiator\RuntimeInstantiator;
use Governor\Tests\Stubs\Dummy1Aggregate;
use Governor\Tests\Stubs\Dummy2Aggregate;

class GovernorFrameworkExtensionTest extends \PHPUnit_Framework_TestCase
{

    private $testSubject;

    public function setUp()
    {
        $this->testSubject = $this->createTestContainer();
    }

    public function testRepositories()
    {
        $repo1 = $this->testSubject->get('dummy1.repository');
        $repo2 = $this->testSubject->get('dummy2.repository');

        $this->assertInstanceOf(RepositoryInterface::class, $repo1);
        $this->assertInstanceOf(RepositoryInterface::class, $repo2);
        $this->assertNotSame($repo1, $repo2);
        $this->assertEquals(Dummy1Aggregate::class,
            $repo1->getClass());
        $this->assertEquals(Dummy2Aggregate::class,
            $repo2->getClass());
    }

    public function testEventHandlers()
    {
        $eventBus = $this->testSubject->get('governor.event_bus.default');

        $this->assertInstanceOf('Governor\Framework\EventHandling\EventBusInterface',
            $eventBus);

        $reflProperty = new \ReflectionProperty($eventBus, 'listeners');
        $reflProperty->setAccessible(true);

        $listeners = $reflProperty->getValue($eventBus);

        $this->assertCount(2, $listeners);
        $this->assertContainsOnlyInstancesOf('Governor\Framework\EventHandling\EventListenerInterface',
            $listeners);
    }

    public function testEventHandlerLazyLoading()
    {
        foreach ($this->testSubject->getServiceIds() as $id) {
            if (preg_match('/^governor.event_handler.*/', $id)) {
                $def = $this->testSubject->getDefinition($id);

                $this->assertTrue($def->isLazy());
            }
        }
    }

    public function testCommandHandlerLazyLoading()
    {
        foreach ($this->testSubject->getServiceIds() as $id) {
            if (preg_match('/^governor.command_handler.*/', $id)) {
                $def = $this->testSubject->getDefinition($id);

                $this->assertTrue($def->isLazy());
            }
        }
    }

    public function createTestContainer()
    {
        $config = array(
                    'governor' => array(
                        'repositories' => array(
                            'dummy1' => array(
                                'aggregate_root' => 'Governor\Tests\Stubs\Dummy1Aggregate',
                                'type' => 'orm'), 
                            'dummy2' => array(
                                'aggregate_root' => 'Governor\Tests\Stubs\Dummy2Aggregate',
                                'type' => 'orm')
                        ),
                    'aggregate_command_handlers' => array(
                        'dummy1' => array(
                            'aggregate_root' => 'Governor\Tests\Stubs\Dummy1Aggregate',
                            'repository' => 'dummy1.repository'
                        ),
                        'dummy2' => array(
                            'aggregate_root' => 'Governor\Tests\Stubs\Dummy2Aggregate',
                            'repository' => 'dummy2.repository'
                        )
                    ),                   
                    'command_buses' => array (
                        'default' => array(
                            'class' => 'Governor\Framework\CommandHandling\SimpleCommandBus'
                        )
                    ),
                    'event_buses' => array(
                        'default' => array (
                            'class' => 'Governor\Framework\EventHandling\SimpleEventBus'
                        )
                    ),
                    'command_gateways' => array(
                        'default' => array (
                            'class' => 'Governor\Framework\CommandHandling\Gateway\DefaultCommandGateway'
                        )
                    ),
                    'clusters' => array(
                        'default' => array (
                            'class' => 'Governor\Framework\EventHandling\SimpleCluster',
                            'order_resolver' => 'governor.order_resolver'
                        )
                    ),
                    'saga_repository' => array(
                        'type' => 'orm',
                        'parameters' => array (
                            'entity_manager' => 'default_entity_manager'
                        )
                    ),
                    'saga_manager' => array(
                        'type' => 'annotation',
                        'saga_locations' => array (
                            sys_get_temp_dir()
                        ) 
                    ),
                    'cluster_selector' => array(
                        'class' => 'Governor\Framework\EventHandling\DefaultClusterSelector'
                    ),
                    'order_resolver' => 'annotation'
                )
            );

        $container = new ContainerBuilder(new ParameterBag(array(
            'kernel.debug' => false,
            'kernel.bundles' => array(),
            'kernel.cache_dir' => sys_get_temp_dir(),
            'kernel.environment' => 'test',
            'kernel.root_dir' => __DIR__ . '/../../../../' // src dir
        )));

        $loader = new GovernorFrameworkExtension();

        $container->setProxyInstantiator(new RuntimeInstantiator());

        $container->registerExtension($loader);
        $container->set('doctrine.orm.default_entity_manager',
            $this->getMock(\Doctrine\ORM\EntityManager::class,
                array(
                'find', 'flush', 'persist', 'remove'), array(), '', false));

        $container->set('logger', $this->getMock(\Psr\Log\LoggerInterface::class));
        $container->set('jms_serializer', $this->getMock(\JMS\Serializer\SerializerInterface::class));
        $container->set('validator', $this->getMock(\Symfony\Component\Validator\ValidatorInterface::class));
        
        $this->addTaggedCommandHandlers($container);
        $this->addTaggedEventListeners($container);

        $loader->load($config, $container);

        $container->addCompilerPass(new CommandHandlerPass(),
            PassConfig::TYPE_BEFORE_REMOVING);
        $container->addCompilerPass(new EventHandlerPass(),
            PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile();

        return $container;
    }

    private function addTaggedCommandHandlers(ContainerBuilder $container)
    {
        $definition = new Definition(ContainerCommandHandler1::class);
        $definition->addTag('governor.command_handler')
            ->setPublic(true);

        $container->setDefinition('test.command_handler', $definition);
    }

    private function addTaggedEventListeners(ContainerBuilder $container)
    {
        $definition = new Definition(ContainerEventListener1::class);
        $definition->addTag('governor.event_handler')
            ->setPublic(true);

        $container->setDefinition('test.event_handler', $definition);
    }

}

class ContainerCommand1
{
    
}

class ContainerEvent1
{
    
}

class ContainerCommandHandler1
{

    /**
     * @CommandHandler
     */
    public function onCommand1(ContainerCommand1 $command)
    {
        
    }

}

class ContainerEventListener1
{

    /**
     * @EventHandler
     */
    public function onEvent1(ContainerEvent1 $event)
    {
        
    }

}
