<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\AppInfo;
use OCP\AppFramework\App;
final class Application extends App {
    public function __construct() {
        parent::__construct('stratus2_ai');
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) { require_once $autoload; }
    }
}
