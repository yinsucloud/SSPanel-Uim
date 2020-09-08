<?php

/**
 * Author: yinsu
 * Date: 2020/9/6
 * Time: 23:23 PM
 */

namespace App\Services\Gateway;

use App\Models\Paylist;
use App\Services\Config;
use App\Services\Auth;

class FCPay extends AbstractPayment
{
    private $fcpayId;
    private $fcpayKey;
    private $baseUrl;

    public function __construct(string $gatewayBaseUrl = "https://www.futurecart.club/")
    {
        $this->fcpayId = Config::get("fcpay_id");
        $this->fcpayKey = Config::get("fcpay_key");
        $this->baseUrl = $gatewayBaseUrl;
    }

    private function getCallbackUrl()
    {
        return Config::get('baseUrl') . 'user/code';
    }

    private function getNotifyUrl()
    {
        return Config::get('baseUrl') . 'payment/notify';
    }

    private function sign_url($data)
    {
        // 签名生成方式，MD5(商户号码 + 订单号码 + 支付方式 + 金额 + 时间 + 同步回调地址+ 异步回调地址+用户密钥)
        return md5(
            $this->fcpayId . $data['out_trade_no'] . $data['type'] . $data['money'] . $data['mwDatetime'] . $this->getCallbackUrl() . $this->getNotifyUrl() . $this->fcpayKey
        );
    }

    private function validate_response($data)
    {
        $sign = $data['sign'];
        // return strtoupper(md5(
        //     $this->fcpayId . $data['system_order_no'] . $data['out_trade_no'] . $data['type'] . $data['money'] . $data['datetime'] . $data['trade_status']
        // )) == strtoupper($sign);
        // 支付网关的签名尚不稳定，待稳定后再加入。
        // 用户也可参考文档自行加入
        return true;
    }

    public function purchase($request, $response, $args)
    {
        $user = Auth::getUser();
        $price = $request->getParam('price');
        $type = $request->getParam('type'); // alipay, wxpay
        $name = $request->getParam('name', '生活用品'); // 商品名称

        $nowTime = time();

        $pl = new Paylist();
        $pl->userid = $user->id;
        $pl->total = $price * Config::get("fcpay_ex_rate");
        $pl->tradeno = $nowTime . 'UID' . $user->id;
        $pl->datetime = $nowTime;
        $pl->save();

        $data = array(
            'pid' => $this->fcpayId,
            'type' => $type, //alipay, wxpay
            'out_trade_no' => $pl->tradeno,
            'return_url' => $this->getCallbackUrl(),
            'notify_url' => $this->getNotifyUrl(),
            'name' => $name,
            'money' => $price,
            'mwDatetime' => date("Y/m/d H:i:s", $nowTime),
        ); //构造需要传递的参数

        $urls = '';

        foreach ($data as $key => $val) { //遍历需要传递的参数
            $urls .= "&$key=" . $val; //拼接为url参数形式并URL编码参数值
        }
        $query = $urls . '&signature=' . $this->sign_url($data); //创建订单所需的参数
        $url = $this->baseUrl . 'PaymentAPI/securepay?' . substr($query, 1);

        header('Location:' . $url);
    }

    public function notify($request, $response, $args)
    {

        $post_data = json_decode(file_get_contents('php://input'), true);

        if (!$this->validate_response($post_data)) {
            exit('签名验证失败');
        }
        if ($post_data['trade_status'] != "success") {
            exit('fail');
        }

        $this->postPayment($post_data['out_trade_no'], 'FC支付');

        exit('success');
    }

    public function getPurchaseHTML()
    {
        return '
        <div class="card-inner">
        <p class="card-heading">请输入充值金额(美金)</p>
        <form class="fcpay" name="fcpay" action="/user/code/fcpay" method="get">
            <input class="form-control maxwidth-edit" id="price" name="price" placeholder="输入充值金额后，点击你要付款的应用图标即可. 注意金额是美金" autofocus="autofocus" type="number" min="0.01" max="1000" step="0.01" required="required">
            <p>当前入账汇率: <font size="4" color="#399AF2">' . Config::get("fcpay_ex_rate") . '</font>. （无论实际支付，入账金额 = 充值金额 X 入账汇率）</p>
            <br>
            <button class="btn btn-flat waves-attach" id="btnSubmit" type="submit" name="type" value="alipay" ><img src="/images/alipay.jpg" width="50px" height="50px" /></button>
            <button class="btn btn-flat waves-attach" id="btnSubmit" type="submit" name="type" value="wxpay" ><img src="/images/weixin.jpg" width="50px" height="50px" /></button>

        </form>
        </div>
';
    }

    public function getReturnHTML($request, $response, $args)
    {
        // TODO: Implement getReturnHTML() method.
    }

    public function getStatus($request, $response, $args)
    {
        // TODO: Implement getStatus() method.
    }
}
