<?php

namespace Drupal\filetoken\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\file\Plugin\Field\FieldFormatter\FileFormatterBase;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceFormatterBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\UrlGeneratorInterface;

/**
 * Plugin implementation of the 'token_formatter' formatter.
 *
 * @FieldFormatter(
 *   id = "token_formatter",
 *   label = @Translation("Token Formatter"),
 *   field_types = {
 *     "image","file","video","audio"
 *   }
 * )
 */
class TokenFormatter extends FileFormatterBase {

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The file URL generator service.
   *
   * @var \Drupal\Core\UrlGeneratorInterface
   */
  protected $urlGenerator;

  /**
   * The logger service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Constructs a TokenFormatter object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Drupal\Core\UrlGeneratorInterface $url_generator
   *   The URL generator service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger service.
   */
  public function __construct(Connection $database, UrlGeneratorInterface $url_generator, LoggerChannelFactoryInterface $logger) {
    $this->database = $database;
    $this->urlGenerator = $url_generator;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    $entity = $items->getEntity();
    
    // Disable page caching for the current request to avoid serving stale content.
    \Drupal::service('page_cache_kill_switch')->trigger();

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $file) {
      $host = $this->getHostUrl();
      $getSrc = $this->getTokenID($this->generateFileUrl($file), $entity->id());

      $elements[$delta] = [
        '#markup' => $host . $getSrc,
      ];
    }

    return $elements;
  }

  /**
   * Generates the file URL from the file object.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file object.
   *
   * @return string
   *   The file URL.
   */
  private function generateFileUrl($file) {
    return $this->urlGenerator->generateString($file->getFileUri());
  }

  /**
   * Generates the base URL with 'https' scheme.
   *
   * @return string
   *   The base URL with the 'https' scheme.
   */
  private function getHostUrl() {
    $base_url = \Drupal::config('system.site')->get('url');
    return str_replace('http', 'https', $base_url) . '/getfilesrc/';
  }

  /**
   * Retrieves or generates a token ID for the given file URL and entity ID.
   *
   * @param string $imgurl
   *   The image URL.
   * @param int $entityid
   *   The entity ID.
   * @param int $length
   *   The length of the token string.
   *
   * @return string
   *   The generated or existing token.
   */
  public function getTokenID($imgurl, $entityid, $length = 15) {
    // Sanitize inputs to avoid SQL injection.
    $imgurl = filter_var($imgurl, FILTER_SANITIZE_URL);

    // Get the existing token if it exists.
    $existing_token = $this->database->select('filetoken_list', 't')
      ->fields('t', ['token'])
      ->condition('image_url', $imgurl)
      ->condition('entity_id', $entityid)
      ->execute()
      ->fetchField();

    // If the token already exists, return it.
    if ($existing_token) {
      return $existing_token;
    }

    // Otherwise, generate a new token.
    $randomString = $this->generateRandomString($length);
    $token = strtotime("now") . $randomString;

    // Insert the new token into the database.
    try {
      $this->database->insert('filetoken_list')
        ->fields([
          'token',
          'entity_id',
          'image_url',
          'exp_timestamp',
          'request_timestamp',
        ])
        ->values([
          $token,
          $entityid,
          $imgurl,
          strtotime("+1 hour"),
          strtotime("now"),
        ])
        ->execute();
    } catch (\Exception $e) {
      $this->logger->get('filetoken')->error('Error inserting token: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $token;
  }

  /**
   * Generates a random string of a given length.
   *
   * @param int $length
   *   The length of the random string.
   *
   * @return string
   *   The random string.
   */
  private function generateRandomString($length) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';

    for ($i = 0; $i < $length; $i++) {
      $randomString .= $characters[rand(0, $charactersLength - 1)];
    }

    return $randomString;
  }

  /**
   * Factory method for creating the service.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   *
   * @return \Drupal\filetoken\Plugin\Field\FieldFormatter\TokenFormatter
   *   A new instance of TokenFormatter.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('url_generator'),
      $container->get('logger.factory')
    );
  }
}
