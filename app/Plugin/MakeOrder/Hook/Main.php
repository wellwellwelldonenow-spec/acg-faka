<?php
declare(strict_types=1);

namespace App\Plugin\MakeOrder\Hook;


use App\Controller\Base\View\ManagePlugin;
use Kernel\Annotation\Hook;
use Kernel\Consts\Base;
use Kernel\Plugin\Entity\Column;
use Kernel\Util\Context;

class Main extends ManagePlugin
{

    /**
     * @return array|null
     */
    #[Hook(point: \App\Consts\Hook::HACK_ROUTE_TABLE_COLUMNS)]
    public function aide(): ?array
    {
        $route = Context::get(Base::ROUTE);
        if (!str_starts_with($route, "/admin")) {
            return null;
        }

        $code = <<<JS
{
            field: '_account_transaction_ship_button_hook', title: '', type: 'button', buttons: [ 
                {
                    icon: 'fa-duotone fa-regular fa-circle-check',
                    class: 'text-primary',
                    title : '补单', 
                    click: (event, value, row, index) => {
                         message.ask("您正在进行补单操作，是否继续？" , () => {
                            util.post("/plugin/makeOrder/api/make" , {order_id: row.id} , res => {
                                message.success(res.msg);
                                $("#order-table").bootstrapTable('refresh' , {silent : true});
                            }); 
                         });
                    },
                    show: row => row?.status == 0
                }
            ]
        }
JS;

        return (new Column("/admin/api/order/data", $code, "widget", "after"))->toArray();
    }

}