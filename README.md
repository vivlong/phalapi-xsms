# PhalApi 2.x 的第三方短信推送扩展
PhalApi 2.x 扩展类库：支持AliyunSMS的推送扩展。

## 安装和配置
修改项目下的composer.json文件，并添加：  
```
    "vivlong/phalapi-xsms":"dev-master"
```
然后执行```composer update```。  

安装成功后，添加以下配置到/path/to/phalapi/config/app.php文件：  
```php
    /**
     * 相关配置
     */
    'Xsms' =>  array(
        'aliyun' => array(
            'accessKeyId'       => '<yourAccessKeyId>',
            'accessKeySecret'   => '<yourAccessKeySecret>',
            'endpoint'          => 'cn-hangzhou',
        ),
    ),
```
并根据自己的情况修改填充。 

## 注册
在/path/to/phalapi/config/di.php文件中，注册：  
```php
$di->xSms = function() {
        return new \PhalApi\Xsms\Lite();
};
```

## 使用
使用方式：
```php
  \PhalApi\DI()->xSms->sendSms();
```  