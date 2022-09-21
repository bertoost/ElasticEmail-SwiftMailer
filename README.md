<div style="text-align: center;">
    <img src="docs/logo.svg" style="width:25%;" alt="Elastic Email">
</div>

# Elastic Email SwiftMailer

A SwiftMailer transport implementation for Elastic Email.

If you found an issue, feel free to raise an issue.

## Installation

```bash
composer require bertoost/elasticemail-swiftmailer
```

## Usage example

```php
use ElasticEmail\Api\EmailsApi;

$config = Configuration::getDefaultConfiguration()
    ->setApiKey('X-ElasticEmail-ApiKey', 'YOUR_API_KEY');
    
$apiInstance = new EmailsApi(new Client(), $config);

$transport = new ElasticEmailTransport($dispathEvent, $apiInstance);
```