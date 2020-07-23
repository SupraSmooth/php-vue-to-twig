<?php

declare(strict_types=1);

namespace Paneon\VueToTwig\Utils;

use Paneon\VueToTwig\Models\Property;
use Paneon\VueToTwig\Models\Replacements;
use ReflectionException;

class TwigBuilder
{
    protected const OPEN = 0;

    protected const CLOSE = 1;

    /**
     * @var mixed[]
     */
    protected $options;

    /**
     * TwigBuilder constructor.
     *
     * @param mixed[] $options
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge(
            [
                'tag_comment' => ['{#', '#}'],
                'tag_block' => ['{%', '%}'],
                'tag_variable' => ['{{', '}}'],
                'whitespace_trim' => '-',
                'interpolation' => ['#{', '}'],
            ],
            $options
        );
    }

    public function createSet(string $name): string
    {
        return $this->createBlock('set ' . $name);
    }

    public function closeSet(): string
    {
        return $this->createBlock('endset');
    }

    public function createVariable(string $name, string $assignment): string
    {
        return $this->createBlock('set ' . $name . ' = ' . $assignment);
    }

    public function createDefaultForVariable(string $name, string $defaultValue): string
    {
        return $this->createBlock('set ' . $name . ' = ' . $name . '|default(' . $defaultValue . ')');
    }

    public function createMultilineVariable(string $name, string $assignment): string
    {
        return $this->createBlock('set ' . $name)
            . $assignment
            . $this->createBlock('endset');
    }

    /**
     * @throws ReflectionException
     */
    public function createIf(string $condition): string
    {
        $condition = $this->refactorCondition($condition);

        return $this->createBlock('if ' . $condition);
    }

    /**
     * @throws ReflectionException
     */
    public function createElseIf(string $condition): string
    {
        $condition = $this->refactorCondition($condition);

        return $this->createBlock('elseif ' . $condition);
    }

    public function createElse(): string
    {
        return $this->createBlock('else');
    }

    public function createEndIf(): string
    {
        return $this->createBlock('endif');
    }

    public function createForItemInList(string $item, string $list): string
    {
        return $this->createBlock('for ' . $item . ' in ' . $list);
    }

    public function createForKeyInList(string $key, string $list): string
    {
        return $this->createBlock('for ' . $key . ' in ' . $list);
    }

    public function createFor(string $list, ?string $item = null, ?string $key = null): ?string
    {
        if ($item !== null && $key !== null) {
            return $this->createBlock('for ' . $key . ', ' . $item . ' in ' . $list);
        }
        if ($item !== null) {
            return $this->createForItemInList($item, $list);
        }
        if ($key !== null) {
            return $this->createForKeyInList($key, $list);
        }

        return null;
    }

    public function createEndFor(): string
    {
        return $this->createBlock('endfor');
    }

    public function createComment(string $comment): string
    {
        return $this->options['tag_comment'][self::OPEN] . ' ' . $comment . ' ' . $this->options['tag_comment'][self::CLOSE];
    }

    /**
     * @param string[] $comments
     */
    public function createMultilineComment(array $comments): string
    {
        return $this->options['tag_comment'][self::OPEN] . ' ' . implode(
            "\n",
            $comments
        ) . ' ' . $this->options['tag_comment'][self::CLOSE];
    }

    public function createBlock(string $content): string
    {
        return "\n" . $this->options['tag_block'][self::OPEN] . ' ' . $content . ' ' . $this->options['tag_block'][self::CLOSE];
    }

    /**
     * @param Property[] $variables
     */
    public function createIncludePartial(string $partialPath, array $variables = []): string
    {
        $hasClassProperty = false;
        foreach ($variables as $variable) {
            if ($variable->getName() === 'class') {
                $hasClassProperty = true;
            }
        }

        if (!$hasClassProperty) {
            $variables[] = new Property('class', '""', false);
        }

        $serializedProperties = $this->serializeComponentProperties($variables);

        return $this->createBlock('include "' . $partialPath . '" with ' . $serializedProperties);
    }

    /**
     * @param Property[] $properties
     */
    public function serializeComponentProperties(array $properties): string
    {
        $props = [];

        /** @var Property $property */
        foreach ($properties as $property) {
            if ($property->getName() === 'key') {
                continue;
            }

            $props[] = '\'' . $property->getName() . '\'' . ': ' . $property->getValue();
        }

        return '{ ' . implode(', ', $props) . ' }';
    }

    public function sanitizeAttributeValue(string $value): string
    {
        $value = Replacements::sanitizeSingleReplacement($value, Replacements::PIPE);

        return $value;
    }

    /**
     * @throws ReflectionException
     */
    public function refactorCondition(string $condition): string
    {
        $refactoredCondition = '';
        $charsCount = mb_strlen($condition, 'UTF-8');
        $quoteChar = null;
        $lastChar = null;
        $buffer = '';

        for ($i = 0; $i < $charsCount; ++$i) {
            $char = mb_substr($condition, $i, 1, 'UTF-8');
            if ($quoteChar === null && ($char === '"' || $char === '\'')) {
                $quoteChar = $char;
                if ($buffer !== '') {
                    $refactoredCondition .= $this->refactorConditionPart($buffer);
                    $buffer = '';
                }
                $refactoredCondition .= $char;
            } elseif ($quoteChar === $char && $lastChar !== '\\') {
                $quoteChar = null;
                $refactoredCondition .= $char;
            } else {
                if ($quoteChar === null) {
                    $buffer .= $char;
                } else {
                    $refactoredCondition .= $char;
                }
            }
            $lastChar = $char;
        }
        if ($buffer !== '') {
            $refactoredCondition .= $this->refactorConditionPart($buffer);
        }

        return $refactoredCondition;
    }

    /**
     * @throws ReflectionException
     */
    private function refactorConditionPart(string $condition): string
    {
        $condition = str_replace('===', '==', $condition);
        $condition = str_replace('!==', '!=', $condition);
        $condition = str_replace('&&', 'and', $condition);
        $condition = str_replace('||', 'or', $condition);
        $condition = preg_replace('/!([^=])/', 'not $1', $condition);
        $condition = str_replace('.length', '|length', $condition);
        $condition = str_replace('.trim', '|trim', $condition);

//        $condition = $this->convertConcat($condition);

        foreach (Replacements::getConstants() as $constant => $value) {
            $condition = str_replace($value, Replacements::getSanitizedConstant($constant), $condition);
        }

        return $condition;
    }

    /**
     * @throws ReflectionException
     */
    public function refactorTextNode(string $content): string
    {
        $refactoredContent = '';
        $charsCount = mb_strlen($content, 'UTF-8');
        $open = false;
        $lastChar = null;
        $quoteChar = null;
        $buffer = '';

        for ($i = 0; $i < $charsCount; ++$i) {
            $char = mb_substr($content, $i, 1, 'UTF-8');
            if ($open === false) {
                $refactoredContent .= $char;
                if ($char === '{' && $lastChar === '{') {
                    $open = true;
                }
            } else {
                $buffer .= $char;
                if ($quoteChar === null && ($char === '"' || $char === '\'')) {
                    $quoteChar = $char;
                } elseif ($quoteChar === $char && $lastChar !== '\\') {
                    $quoteChar = null;
                }
                if ($quoteChar === null && $char === '}' && $lastChar === '}') {
                    $open = false;
                    $buffer = $this->convertTemplateString(trim($buffer, '}'));
                    $refactoredContent .= $this->refactorCondition($buffer) . '}}';
                    $buffer = '';
                }
            }
            $lastChar = $char;
        }

        return $refactoredContent;
    }

    public function convertConcat(string $content): string
    {
        if (preg_match_all('/(\S*)(\s*\+\s*(\S+))+/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $parts = explode('+', $match[0]);
                $lastPart = null;
                $convertedContent = '';
                foreach ($parts as $part) {
                    $part = trim($part);
                    if ($lastPart !== null) {
                        if (is_numeric($lastPart) && is_numeric($part)) {
                            $convertedContent .= ' + ' . $part;
                        } else {
                            $convertedContent .= ' ~ ' . $part;
                        }
                    } else {
                        $convertedContent = $part;
                    }
                    $lastPart = $part;
                }
                $content = str_replace($match[0], $convertedContent, $content);
            }
        }

        return $content;
    }

    private function convertTemplateString(string $content): string
    {
        if (preg_match_all('/`([^`]+)`/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $match[1] = str_replace('${', '\' ~ ', $match[1]);
                $match[1] = str_replace('}', ' ~ \'', $match[1]);
                $content = str_replace($match[0], '\'' . $match[1] . '\'', $content);
            }
        }

        return $content;
    }

    public function createVariableOutput(string $varName, ?string $fallbackVariableName = null): string
    {
        if ($fallbackVariableName) {
            return '{{ ' . $varName . '|default(' . $fallbackVariableName . ') }}';
        }

        return '{{ ' . $varName . ' }}';
    }
}
