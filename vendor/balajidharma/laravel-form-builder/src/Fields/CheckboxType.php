<?php

namespace BalajiDharma\LaravelFormBuilder\Fields;

class CheckboxType extends FormField
{
    const DEFAULT_VALUE = 1;

    /**
     * {@inheritdoc}
     */
    protected $valueProperty = 'checked';

    /**
     * {@inheritdoc}
     */
    protected function getTemplate()
    {
        return 'checkbox';
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaults()
    {
        return [
            'attr' => ['id' => $this->getName()],
            'value' => self::DEFAULT_VALUE,
            'checked' => null,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function isValidValue($value)
    {
        return $value !== null;
    }
}
