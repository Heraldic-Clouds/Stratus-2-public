<?php
declare(strict_types=1);
namespace OCA\Stratus2AI\Service;
use Aws\Api\ApiProvider;
use Aws\EndpointV2\EndpointProviderV2;
use Aws\Sqs\SqsClient;
final class AnalysisQueue {
    public static function client(array $options = []): SqsClient {
        // Nextcloud loads an S3-only AWS SDK first. Resolve SQS models from this
        // app's locked dependency even when the SDK's shared classes are loaded.
        $models = __DIR__.'/../../vendor/aws/aws-sdk-php/src/data';
        return new SqsClient(array_replace([
            'version'=>'2012-11-05',
            'region'=>getenv('AWS_REGION') ?: 'us-west-2',
            'api_provider'=>ApiProvider::filesystem($models),
            'endpoint_provider'=>new EndpointProviderV2(
                require $models.'/sqs/2012-11-05/endpoint-rule-set-1.json.php',
                require $models.'/partitions.json.php'
            ),
        ], $options));
    }
}
