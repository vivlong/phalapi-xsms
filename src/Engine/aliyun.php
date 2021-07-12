<?php

namespace PhalApi\Xsms;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;
use AlibabaCloud\Dybaseapi\MNS\Requests\BatchDeleteMessage;
use AlibabaCloud\Dybaseapi\MNS\Requests\BatchReceiveMessage;

class Aliyun
{
    protected $config;
    protected $debug;

    public function __construct($config = null)
    {
        $di = \PhalApi\DI();
        $this->debug = $di->debug;
        $this->config = $config;
        if (null == $this->config) {
            $this->config = $di->config->get('app.Xsms.aliyun');
        }
        if (!$this->config) {
            $di->logger->info(__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, 'No engine config');

            return false;
        }
        AlibabaCloud:: accessKeyClient($this->config['accessKeyId'], $this->config['accessKeySecret'])
            ->regionId($this->config['regionId'])   // 设置客户端区域，使用该客户端且没有单独设置的请求都使用此设置
            ->timeout(6)                            // 超时10秒，使用该客户端且没有单独设置的请求都使用此设置
            ->connectTimeout(10)                    // 连接超时10秒，当单位小于1，则自动转换为毫秒，使用该客户端且没有单独设置的请求都使用此设置
            //->debug(true) 						// 开启调试，CLI下会输出详细信息，使用该客户端且没有单独设置的请求都使用此设置
            ->asDefaultClient();
    }

    public function getConfig()
    {
        return $this->config;
    }

    private function rpcRequest($action, $params)
    {
        $di = \PhalApi\DI();
        $rs = [
            'code' => 0,
            'msg' => '',
            'data' => null,
        ];
        try {
            $result = AlibabaCloud::rpc()
                ->product('Dysmsapi')
                ->version('2017-05-25')
                ->action($action)
                ->method('POST')
                ->host('dysmsapi.aliyuncs.com')
                ->options([
                    'query' => array_merge([
                        'RegionId' => $this->config['regionId'],
                    ], $params),
                ])
                ->request();
            if ($result->isSuccess()) {
                $rs['code'] = 1;
                $rs['msg'] = 'success';
                $rs['data'] = $result->toArray();
                if ($this->debug) {
                    $di->logger->info(__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['Response' => $rs['data']]);
                }
            } else {
                $rs['code'] = -1;
                $rs['msg'] = $result;
                if ($this->debug) {
                    $di->logger->info(__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['Failed' => $result]);
                }
            }
        } catch (ClientException $e) {
            $di->logger->error(__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['ClientException' => $e->getErrorMessage()]);
            $rs['code'] = -1;
            $rs['msg'] = $result;
            $di->logger->error(__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['Failed' => $result]);
        } catch (ServerException $e) {
            $di->logger->error(__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['ServerException' => $e->getErrorMessage()]);
            $rs['code'] = -1;
            $rs['msg'] = $result;
            $di->logger->error(__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['Failed' => $result]);
        }

        return $rs;
    }

    /**
     * 发送短信
     *
     * @return stdClass
     */
    public function sendSms($phoneNo, $sign, $tplCode, $tplParam = [], $outId = null, $extendCode = null)
    {
        $params = [
            'PhoneNumbers' => $phoneNo,
            'SignName' => $sign,
            'TemplateCode' => $tplCode,
        ];
        if (!empty($tplParam)) {
            $params['TemplateParam'] = json_encode($tplParam, JSON_UNESCAPED_UNICODE);
        }
        if (!empty($outId)) {
            $params['OutId'] = $outId;
        }
        if (!empty($extendCode)) {
            $params['SmsUpExtendCode'] = $extendCode;
        }

        return $this->rpcRequest('SendSms', $params);
    }

    /**
     * 批量发送短信
     *
     * @return stdClass
     */
    public function sendBatchSms($phoneNoJson, $signNameJson, $tplCode, $tplParamJson, $extendCodeJson = null)
    {
        $params = [
            'PhoneNumberJson' => json_encode($phoneNoJson, JSON_UNESCAPED_UNICODE),
            'SignNameJson' => json_encode($signNameJson, JSON_UNESCAPED_UNICODE),
            'TemplateCode' => $tplCode,
            'TemplateParamJson' => json_encode($tplParamJson, JSON_UNESCAPED_UNICODE),
        ];
        if (!empty($extendCodeJson)) {
            $params['SmsUpExtendCodeJson'] = json_encode($extendCodeJson, JSON_UNESCAPED_UNICODE);
        }

        return $this->rpcRequest('SendBatchSms', $params);
    }

    /**
     * 短信发送记录查询.
     *
     * @return stdClass
     */
    public function querySendDetails($phoneNo, $dateYmd, $bizId)
    {
        $params = [
            'PhoneNumber' => $phoneNo,
            'SendDate' => $dateYmd,
            'PageSize' => 10,
            'CurrentPage' => 1,
        ];
        if (!empty($bizId)) {
            $params['BizId'] = $bizId;
        }

        return $this->rpcRequest('SendBatchSms', $params);
    }

    /**
     * 获取批量消息.
     *
     * @param string   $messageType 消息类型
     * @param string   $queueName   在云通信页面开通相应业务消息后，就能在页面上获得对应的queueName<br/>(e.g. Alicom-Queue-xxxxxx-xxxxxReport)
     * @param callable $callback    <p>
     *                              回调仅接受一个消息参数;
     *                              <br/>回调返回true，则工具类自动删除已拉取的消息;
     *                              <br/>回调返回false,消息不删除可以下次获取.
     *                              <br/>(e.g. function ($message) { return true; }
     *                              </p>
     */
    public function receiveBatchMsg($messageType, $queueName, callable $callback)
    {
        $di = \PhalApi\DI();
        $i = 0;
        $params = [
            'MessageType' => $messageType,
            'QueueName' => $queueName,
        ];
        $response = null;
        $token = null;
        // 取回执消息失败3次则停止循环拉取
        do {
            try {
                if (null == $token || strtotime($token['ExpireTime']) - time() > 2 * 60) {
                    $response = $this->rpcRequest('QueryTokenForMnsQueue', $params);
                }
                $token = $response['data']['MessageTokenDTO'];
                $mnsClient = new \AlibabaCloud\Dybaseapi\MNS\MnsClient(
                    'http://1943695596114318.mns.cn-hangzhou.aliyuncs.com',
                    $token['AccessKeyId'],
                    $token['AccessKeySecret'],
                    $token['SecurityToken']
                );
                $mnsRequest = new BatchReceiveMessage(10, 5);
                $mnsRequest->setQueueName($queueName);
                $mnsResponse = $mnsClient->sendRequest($mnsRequest);
                $receiptHandles = [];
                foreach ($mnsResponse->Message as $message) {
                    $messageBody = base64_decode($message->MessageBody); // base64解码后的JSON字符串
                    // 计算消息体的摘要用作校验
                    $bodyMD5 = strtoupper(md5($messageBody));
                    // 比对摘要，防止消息被截断或发生错误
                    if ($bodyMD5 == $message->MessageBodyMD5) {
                        // 执行回调
                        if (call_user_func($callback, $messageBody)) {
                            // 当回调返回真值时，删除已接收的信息
                            $receiptHandles[] = $message->ReceiptHandle; // 加入$receiptHandles数组中的记录将会被删除
                        }
                    }
                }
                if (count($receiptHandles) > 0) {
                    $deleteRequest = new BatchDeleteMessage($queueName, $receiptHandles);
                    $mnsClient->sendRequest($deleteRequest);
                }
            } catch (ClientException $e) {
                $di->logger->error(__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['ClientException' => $e->getErrorMessage()]);
            } catch (ServerException $e) {
                if (404 == $e->getCode()) {
                    ++$i;
                }
                $di->logger->error(__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['ServerException' => $e->getErrorMessage()]);
            }
        } while ($i < 3);
    }
}
