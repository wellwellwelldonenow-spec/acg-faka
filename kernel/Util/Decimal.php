<?php
declare (strict_types=1);

namespace Kernel\Util;


class Decimal
{
    /**
     * @var bool|null
     */
    private static ?bool $hasBcMath = null;

    /**
     * @var string
     */
    private string $amount;

    /**
     * @var int
     */
    private int $scale;

    /**
     * @param string|float|int $amount
     * @param int $scale
     */
    public function __construct(string|float|int $amount, int $scale = 2)
    {
        $this->amount = (string)$amount;
        $this->scale = $scale;
    }

    /**
     * 加法
     * @param string|float|int $other
     * @return Decimal
     */
    public function add(string|float|int $other): Decimal
    {
        if (self::hasBcMath()) {
            $result = bcadd($this->amount, (string)$other, $this->scale);
        } else {
            $result = number_format((float)$this->amount + (float)$other, $this->scale, '.', '');
        }
        return new Decimal($result, $this->scale);
    }

    /**
     * 减法
     * @param string|float|int $other
     * @return Decimal
     */
    public function sub(string|float|int $other): Decimal
    {
        if (self::hasBcMath()) {
            $result = bcsub($this->amount, (string)$other, $this->scale);
        } else {
            $result = number_format((float)$this->amount - (float)$other, $this->scale, '.', '');
        }
        return new Decimal($result, $this->scale);
    }

    /**
     * 乘法
     * @param string|float|int $factor
     * @return Decimal
     */
    public function mul(string|float|int $factor): Decimal
    {
        if (self::hasBcMath()) {
            $result = bcmul($this->amount, (string)$factor, $this->scale);
        } else {
            $result = number_format((float)$this->amount * (float)$factor, $this->scale, '.', '');
        }
        return new Decimal($result, $this->scale);
    }

    /**
     * 除法
     * @param string|float|int $divisor
     * @return Decimal
     */
    public function div(string|float|int $divisor): Decimal
    {
        if (self::hasBcMath()) {
            $result = bcdiv($this->amount, (string)$divisor, $this->scale);
        } else {
            $divisor = (float)$divisor;
            if ($divisor == 0.0) {
                $result = number_format(0, $this->scale, '.', '');
            } else {
                $result = number_format((float)$this->amount / $divisor, $this->scale, '.', '');
            }
        }
        return new Decimal($result, $this->scale);
    }

    /**
     * 获取结果
     * @param int|null $scale
     * @return string
     */
    public function getAmount(?int $scale = 2): string
    {
        $scale ??= 2;
        if (self::hasBcMath()) {
            return bcadd($this->amount, '0', $scale);
        }
        return number_format((float)$this->amount, $scale, '.', '');
    }

    /**
     * @return bool
     */
    private static function hasBcMath(): bool
    {
        if (self::$hasBcMath !== null) {
            return self::$hasBcMath;
        }
        self::$hasBcMath = function_exists('bcadd') && function_exists('bcsub') && function_exists('bcmul') && function_exists('bcdiv');
        return self::$hasBcMath;
    }
}
