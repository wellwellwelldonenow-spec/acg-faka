!function () {

    $('select[name=platform]').change(function () {
        let val = $(this).val();
        if (val == 0) {
            $(".aliyun").show();
            $(".tencent").hide();
            $(".dxbao").hide();
        } else if (val == 1) {
            $(".aliyun").hide();
            $(".dxbao").hide();
            $(".tencent").show();
        } else if (val == 2) {
            $(".aliyun").hide();
            $(".dxbao").show();
            $(".tencent").hide();
        }
    });


    $('.save-data').click(function () {
        let data = util.arrayToObject($("#data-form").serializeArray());
        data.numberAuthEnabled = $('input[name=numberAuthEnabled]').is(':checked') ? 1 : 0;
        data.numberAuthRequired = $('input[name=numberAuthRequired]').is(':checked') ? 1 : 0;
        util.post("/admin/api/config/sms", data, res => {
            layer.msg("保存成功");
        });
    });

    $('.send-test-message').click(function () {
        layer.prompt({title: '(国内)手机号', formType: 0}, function (phone, index) {
            util.post("/admin/api/config/smsTest", {phone: phone}, res => {
                layer.msg(res.msg);
            });
        });
    });
}();
