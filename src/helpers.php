<?php

if(function_exists('str_replace_template') == false)
{
    function str_replace_template($str, $data)
    {
        return preg_replace_callback('/{(.[^}]*)}/', function ($matches) use ($data)
        {
            $match = explode(':', $matches[1]);
            $method = isset($match[1]) ? $match[0] : null;
            $itemIndex = $method ? $match[1] : $match[0];

            if(isset($data[$itemIndex]))
            {
                $item = $data[$itemIndex];

                if ($method && method_exists(\Illuminate\Support\Str::class, $method))
                {
                    $item = \Illuminate\Support\Str::$method($item);
                }

                return $item;
            }

            return '';

        }, $str);
    }
}

if(function_exists('str_rest_url') == false) {
    function str_rest_url($value, $delimiter = '/')
    {
        if (! ctype_lower($value)) {
            $value = preg_replace('/\s+/', '', $value);

            $b = 0;

            $value = strtolower(preg_replace_callback('/(.)(?=[A-Z])/', function($matches) use($delimiter, &$b)
            {
                $result = "{$matches[0]}{$delimiter}{{$b}}{$delimiter}";
                $b++;

                return $result;

            }, $value));
        }

        return $value;
    }
}