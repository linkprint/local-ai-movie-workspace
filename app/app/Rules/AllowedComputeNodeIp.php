<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AllowedComputeNodeIp implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)
            || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || ! $this->isAllowed($value)) {
            $fail(__('ui.compute_nodes.errors.invalid_ip'));
        }
    }

    private function isAllowed(string $ip): bool
    {
        $address = ip2long($ip);
        if ($address === false) {
            return false;
        }

        foreach ((array) config('movie.allowed_node_cidrs', []) as $cidr) {
            if (! is_string($cidr) || ! str_contains($cidr, '/')) {
                continue;
            }

            [$network, $prefix] = explode('/', $cidr, 2);
            $networkAddress = ip2long($network);
            if ($networkAddress === false || ! ctype_digit($prefix)) {
                continue;
            }

            $prefixLength = (int) $prefix;
            if ($prefixLength < 0 || $prefixLength > 32) {
                continue;
            }

            $mask = $prefixLength === 0
                ? 0
                : (0xFFFFFFFF << (32 - $prefixLength)) & 0xFFFFFFFF;

            if (($address & $mask) === ($networkAddress & $mask)) {
                return true;
            }
        }

        return false;
    }
}
