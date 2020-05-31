<?php

namespace Drupal\wizardo\Plugin\GraphQL\DataProducer;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\GraphQL\Buffers\EntityBuffer;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use GraphQL\Deferred;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @DataProducer(
 *   id = "wizardo_route_entity",
 *   name = @Translation("Load entity by URL"),
 *   description = @Translation("The entity belonging to the current url."),
 *   produces = @ContextDefinition("entity",
 *     label = @Translation("Entity")
 *   ),
 *   consumes = {
 *     "path" = @ContextDefinition("string",
 *       label = @Translation("Path")
 *     )
 *   }
 * )
 */
class RouteEntity extends DataProducerPluginBase implements ContainerFactoryPluginInterface {
  use DependencySerializationTrait;

  /**
   * The entity buffer service.
   *
   * @var \Drupal\graphql\GraphQL\Buffers\EntityBuffer
   */
  protected $entityBuffer;

  /**
   * The path validator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('graphql.buffer.entity'),
      $container->get('path.validator'),
    );
  }

  /**
   * RouteEntity constructor.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $pluginId
   *   The plugin id.
   * @param mixed $pluginDefinition
   *   The plugin definition.
   * @param \Drupal\graphql\GraphQL\Buffers\EntityBuffer $entityBuffer
   *   The entity buffer service.
   * @param \Drupal\Core\Path\PathValidatorInterface $pathValidator
   *   The path validator service.
   */
  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition,
    EntityBuffer $entityBuffer,
    PathValidatorInterface $pathValidator
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->entityBuffer = $entityBuffer;
    $this->pathValidator = $pathValidator;
  }

  /**
   * @param string $path
   *
   * @return \GraphQL\Deferred
   */
  public function resolve(string $path) {
    $url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($path);

    [, $type] = explode('.', $url->getRouteName());
    $parameters = $url->getRouteParameters();
    $id = $parameters[$type];
    $resolver = $this->entityBuffer->add($type, $id);

    $request_path = urldecode(trim($path, '/'));
    $path_args = explode('/', $request_path);
    $prefix = array_shift($path_args);

    $translation = \Drupal::languageManager()->getDefaultLanguage()->getId();
    $languages = \Drupal::languageManager()->getLanguages();
    foreach ($languages as $language) {
      if ($language->getId() === $prefix) {
        $translation = $language->getId();
      }
    }

    return new Deferred(function () use ($resolver, $translation) {
      /** @var \Drupal\node\NodeInterface $entity */
      $entity = $resolver();

      // Get the correct translation.
      $entity = $entity->getTranslation($translation);
      $entity->addCacheContexts(["static:language:${$translation}"]);

      return $entity;
    });
  }
}
