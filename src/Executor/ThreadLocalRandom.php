<?php

namespace Concurrent\Executor;

class ThreadLocalRandom
{
    /**
     * The seed increment
     */
    private const GAMMA = 0x9e3779b97f4a7c15;

    /**
     * The increment for generating probe values
     */
    private const PROBE_INCREMENT = 0x9e3779b9;

    /**
     * The increment of seeder per new instance
     */
    private const SEEDER_INCREMENT = 0xbb67ae8584caa73b;

    private static $initialized = false;

    private static $probeGenerator;

    private static $seeder;

    public static function multiplier(string $hex)
    {
        $maxInt = gmp_init('7fffffffffffffff', 16); // 2^63-1 in hexadecimal
        $mult = gmp_init($hex, 16);
        // If the constant represents a negative number in signed 64-bit,
        // adjust it to be the equivalent positive number
        if (gmp_cmp($mult, $maxInt) > 0) {
            $mult = gmp_sub(gmp_pow(2, 64), $mult);
            $mult = gmp_neg($mult); // Negate since it should be a negative value
        }
        return $mult;
    }

    public static function signedMul($a, $b)
    {
        // Multiply the two GMP numbers
        $result = gmp_mul($a, $b);
        
        // Reduce the result modulo 2^64 to emulate 64-bit overflow behavior
        $pow64 = gmp_pow(2, 64);
        $mod = gmp_mod($result, $pow64);
        
        // If the result is greater than 2^63 - 1, it is negative in two's complement
        if (gmp_cmp($mod, gmp_pow(2, 63)) >= 0) {
            // Subtract 2^64 to get the negative two's complement value
            return gmp_sub($mod, $pow64);
        }
        
        return $mod;
    }

    public static function signedAdd($a, $b)
    {
        // Add the two GMP numbers
        $result = gmp_add($a, $b);
                
        // Reduce the result modulo 2^64 to emulate 64-bit overflow behavior
        $pow64 = gmp_pow(2, 64);
        $mod = gmp_mod($result, $pow64);

        // If the result is greater than 2^63 - 1, it is negative in two's complement
        if (gmp_cmp($mod, gmp_pow(2, 63)) >= 0) {
            // Subtract 2^64 to get the negative two's complement value
            return gmp_sub($mod, $pow64);
        }

        return $mod;
    }

    public static function unsignedRightShift($a, $pow)
    {
        $number = gmp_init($a);

        // If the number is negative, convert to a positive number that has the same binary representation
        if (gmp_cmp($number, 0) < 0) {
            // PHP_INT_SIZE should be 8 for a 64-bit system.
            // For 32-bit systems, this needs adjustment.
            $number = gmp_add(gmp_pow(2, 64), $number);
        }
        // Perform the unsigned right shift equivalent
        $result = gmp_div_q($number, gmp_pow(2, $pow));

        return $result;
    }

    public static function unsignedRightShiftWithCast($a, $pow, $castBits = 64)
    {
        // PHP_INT_SIZE should be 8 for a 64-bit system.
        // For 32-bit systems, this needs adjustment.
        $castSize = PHP_INT_SIZE * 8;

        $number = gmp_init($a);

        // Perform the unsigned right shift equivalent
        $shiftedNumber = gmp_div_q($number, gmp_pow(2, $pow));

        // Mask out all but the lowest $castBits bits
        $mask = gmp_sub(gmp_pow(2, $castBits), 1);
        $result = gmp_and($shiftedNumber, $mask);

        // Convert it to an integer
        $result = gmp_intval($result);

        // Detect if the highest bit (sign bit) is set for the specified cast size
        if ($result & gmp_intval(gmp_pow(2, $castBits - 1))) {
            // It's a negative number in $castBits-bit signed representation.
            // Flip all the bits and add one to get the absolute value,
            // then negate to get the correct negative value.
            $result = -(gmp_intval(gmp_sub(gmp_pow(2, $castBits), $result)));
        }

        return $result;
    }

    private static function mix64(int $z)
    {
        $mult1 = self::multiplier('0xff51afd7ed558ccd');
        $mult2 = self::multiplier('0xc4ceb9fe1a85ec53');

        $z = gmp_init($z, 10);

        $z = gmp_xor($z, self::unsignedRightShift($z, 33));
        $z = self::signedMul($z, $mult1);

        $z = gmp_xor($z, self::unsignedRightShift($z, 33));
        $z = self::signedMul($z, $mult2);

        $z = gmp_xor($z, self::unsignedRightShift($z, 33));

        return $z;
    }

    private static function initialSeed()
    {
        $m1 = floor(microtime(true) * 1000);
        $m2 = hrtime(true);
        $r = gmp_xor(  self::mix64($m1) , self::mix64($m2) );
        return $r;
    }

    public static function localInit(\Swoole\Table $threadMeta): void
    {
        $data = $threadMeta->get((string) getmypid());
        if ($data == false) {
            $seed = self::initialSeed();
            $seeder = new \Swoole\Atomic\Long(gmp_strval($seed));

            $probeGenerator = new \Swoole\Atomic\Long(0);
            $p = $probeGenerator->get();
            $probe = ($p == 0) ? 1 : $p; // skip 0
            $probeGenerator->add(self::PROBE_INCREMENT);

            

            $inc = gmp_init('0xbb67ae8584caa73b', 16); 
            $prev = $seeder->get();
            $add = self::signedAdd($seed, $inc);
            $seeder->set(gmp_strval($add));

            $data['pid'] = getmypid();
            $data['probe'] = $probe;
            $data['seed'] = gmp_strval(self::mix64($prev));
            $threadMeta->set((string) getmypid(), $data);
        }        
    }

    public static function advanceProbe(\Swoole\Table $threadMeta, int $probe): int
    {
        $probe ^= $probe << 13;   // xorshift
        $probe ^= gmp_intval(self::unsignedRightShift($probe, 17));
        $probe ^= $probe << 5;
        $data = $threadMeta->get((string) getmypid());
        $data['probe'] = $probe;
        $threadMeta->set((string) getmypid(), $data);
        return $probe;
    }

    public static function getProbe(\Swoole\Table $threadMeta): int
    {
        $data = $threadMeta->get((string) getmypid());
        return $data === false ? 0 : $data['probe'];
    }

    public static function nextSecondarySeed(\Swoole\Table $threadMeta): int
    {
        $r = 0;
        $data = $threadMeta->get((string) getmypid());
        if ($data !== false && ($r = $data['secondary']) !== false) {
            $r = gmp_init($r, 10);
            $r = gmp_xor($r, self::signedMul($r, gmp_pow(2, 13)));
            $b = self::unsignedRightShift($r, 17);
            $r = gmp_xor($r, $b);
            $r = gmp_xor($r, self::signedMul($r, gmp_pow(2, 5)));
            $r = gmp_strval($r);
        } else {
            self::localInit($threadMeta);
            if (($r = $thread->getSeed()->get()) == 0) {
                $r = 1; // avoid zero
            }
        }
        $data['secondary'] = $r;
        $threadMeta->set((string) getmypid(), $data);
        return $r;
    }

    public static function longToInt(int $number): int
    {
        // Simulate Java's long to int casting, taking the 32 least significant bits
        // This is done by first getting the remainder (modulo) when divided by 2^32,
        // to mimic the overflow behavior. Then, we adjust if the result is beyond the
        // 32-bit integer range, similar to how Java treats casting of long to int.
        $number = $number % 4294967296; // Modulo 2^32 to simulate overflow/underflow
        
        // Adjust if the number exceeds 32-bit integer range
        if ($number >= 2147483648) {
            // If in the upper half of the 32-bit range, subtract 2^32 to get the negative counterpart
            $number -= 4294967296;
        } elseif ($number < -2147483648) {
            // This case might not be necessary due to the modulo operation, but it's here for completeness
            $number += 4294967296;
        }
        
        return $number; // Ensure it's returned as an int for consistency
    }
}
