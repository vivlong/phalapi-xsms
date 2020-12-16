<?php

namespace PhalApi\Xsms\Engine;

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

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
                    $di->logger->info(__NAMESPACE__.DIRECTORY_SEPARATOR.__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['Response' => $response['body']]);
                }
            } else {
                $rs['code'] = -1;
                $rs['msg'] = $result;
                if ($this->debug) {
                    $di->logger->info(__NAMESPACE__.DIRECTORY_SEPARATOR.__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['Failed' => $result]);
                }
            }
        } catch (ClientException $e) {
            $di->logger->error(__NAMESPACE__.DIRECTORY_SEPARATOR.__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['ClientException' => $e->getErrorMessage()]);
            $rs['code'] = -1;
            $rs['msg'] = $result;
            $di->logger->error(__NAMESPACE__.DIRECTORY_SEPARATOR.__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['Failed' => $result]);
        } catch (ServerException $e) {
            $di->logger->error(__NAMESPACE__.DIRECTORY_SEPARATOR.__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['ServerException' => $e->getErrorMessage()]);
            $rs['code'] = -1;
            $rs['msg'] = $result;
            $di->logger->error(__NAMESPACE__.DIRECTORY_SEPARATOR.__CLASS__.DIRECTORY_SEPARATOR.__FUNCTION__, ['Failed' => $result]);
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
    public function sendBatchSms($phoneNo, $sign, $tplCode, $tplParam = [], $extendCode = null)
    {
        $params = [
            'PhoneNumberJson' => json_encode($phoneNo, JSON_UNESCAPED_UNICODE),
            'SignNameJson' => json_encode($sign, JSON_UNESCAPED_UNICODE),
            'TemplateCode' => $tplCode,
        ];
        if (!empty($tplParam)) {
            $params['TemplateParamJson'] = json_encode($tplParam, JSON_UNESCAPED_UNICODE);
        }
        if (!empty($extendCode)) {
            $params['SmsUpExtendCodeJson'] = json_encode($extendCode, JSON_UNESCAPED_UNICODE);
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
}
