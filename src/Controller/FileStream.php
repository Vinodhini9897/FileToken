<?php

namespace Drupal\filetoken\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller to retrieve and serve files based on tokens.
 */
class FileStreamController extends ControllerBase {

  /**
   * The database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The request stack service.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The logger channel service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Constructs a FileStreamController object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection service.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger service.
   */
  public function __construct(Connection $database, FileSystemInterface $file_system, RequestStack $request_stack, LoggerChannelFactoryInterface $logger) {
    $this->database = $database;
    $this->fileSystem = $file_system;
    $this->requestStack = $request_stack;
    $this->logger = $logger;
  }

  /**
   * Retrieves and serves the file corresponding to the token.
   *
   * @param string $args1
   *   The token provided in the URL.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The file response.
   */
  public function filesrc($args1) {
    try {
      $request = $this->requestStack->getCurrentRequest();
      $host = $request->getHost();

      // Query database for the file corresponding to the token.
      $query = $this->database->select('filetoken_list', 't')
        ->fields('t', ['image_url', 'token', 'exp_timestamp'])
        ->condition('token', $args1)
        ->execute();
      $result = $query->fetchObject();

      if ($result) {
        $path = $this->getFilePath($result->image_url);

        if ($path && file_exists($path)) {
          return $this->serveFile($path);
        }
        else {
          $this->logError($args1, $path, 'File not found');
          return new Response('File not found', Response::HTTP_NOT_FOUND);
        }
      }
      else {
        $this->logError($args1, '', 'Token not found');
        return new Response('Token not found', Response::HTTP_NOT_FOUND);
      }
    }
    catch (\Exception $e) {
      $this->logger->get('filetoken')->error('Error serving file for token @token: @error', [
        '@token' => $args1,
        '@error' => $e->getMessage(),
      ]);
      return new Response('Something went wrong. Please try again later.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Retrieves the file path based on the image URL.
   *
   * @param string $image_url
   *   The URL of the image.
   *
   * @return string
   *   The full file path.
   */
  private function getFilePath($image_url) {
    $privatePath = $this->fileSystem->realpath('private://');
    $publicPath = $this->fileSystem->realpath('public://');

    // Determine whether the file is public or private.
    if (strpos($image_url, '/system/files') === 0) {
      // Private file.
      $path = str_replace('/system/files', '', $image_url);
      return $privatePath . $path;
    }
    else {
      // Public file.
      return $publicPath . urldecode(str_replace('sites/default/files/', '', $image_url));
    }
  }

  /**
   * Serves the file by setting appropriate headers and returning the response.
   *
   * @param string $path
   *   The file path.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The file response.
   */
  private function serveFile($path) {
    $mime_type = mime_content_type($path);
    $filename = basename($path);
    $file_size = filesize($path);

    $response = new Response(file_get_contents($path));
    $response->headers->set('Content-Type', $mime_type);
    $response->headers->set('Content-Disposition', 'inline; filename="' . $filename . '"');
    $response->headers->set('Content-Length', $file_size);
    $response->headers->set('Cache-Control', 'public, max-age=3600');
    $response->headers->set('Accept-Ranges', 'bytes');

    return $response;
  }

  /**
   * Logs error information for debugging purposes.
   *
   * @param string $token
   *   The token used in the request.
   * @param string $path
   *   The path to the file.
   * @param string $error
   *   The error message.
   */
  private function logError($token, $path, $error) {
    $this->logger->get('filetoken')->error('Error with token @token, path @path: @error', [
      '@token' => $token,
      '@path' => $path,
      '@error' => $error,
    ]);
  }

  /**
   * Factory method for creating the service.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   *
   * @return \Drupal\filetoken\Controller\FileStreamController
   *   A new instance of FileStreamController.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('file_system'),
      $container->get('request_stack'),
      $container->get('logger.factory')
    );
  }
}
