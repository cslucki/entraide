@props(['attributeLabels' => []])

@once
    @push('scripts')
        <script>
            document.addEventListener('DOMContentLoaded', () => {
                const validationMessages = @js([
                    'required' => __('marketplace.validation.required'),
                    'select_required' => __('marketplace.validation.select_required'),
                    'radio_required' => __('marketplace.validation.radio_required'),
                    'too_short' => __('marketplace.validation.too_short'),
                    'too_long' => __('marketplace.validation.too_long'),
                    'range_underflow' => __('marketplace.validation.range_underflow'),
                    'range_overflow' => __('marketplace.validation.range_overflow'),
                    'type_mismatch' => __('marketplace.validation.type_mismatch'),
                    'bad_input' => __('marketplace.validation.bad_input'),
                    'file_type' => __('marketplace.validation.file_type'),
                ]);
                const validationAttributes = @js($attributeLabels);

                const fieldName = (field) => field.name.replace(/\[\]$/, '');
                const fieldLabel = (field) => validationAttributes[field.name] || validationAttributes[fieldName(field)] || fieldName(field);
                const message = (key, field, replacements = {}) => {
                    let text = validationMessages[key] || '';
                    const values = { attribute: fieldLabel(field), ...replacements };
                    Object.entries(values).forEach(([name, value]) => {
                        text = text.replaceAll(`:${name}`, value);
                    });

                    return text;
                };
                const setMessage = (field) => {
                    field.setCustomValidity('');

                    if (field.validity.valid) {
                        return;
                    }

                    if (field.validity.valueMissing) {
                        if (field.tagName === 'SELECT') {
                            field.setCustomValidity(message('select_required', field));
                            return;
                        }
                        if (field.type === 'radio') {
                            field.setCustomValidity(message('radio_required', field));
                            return;
                        }
                        field.setCustomValidity(message('required', field));
                        return;
                    }

                    if (field.validity.tooShort) {
                        field.setCustomValidity(message('too_short', field, { min: field.minLength }));
                        return;
                    }
                    if (field.validity.tooLong) {
                        field.setCustomValidity(message('too_long', field, { max: field.maxLength }));
                        return;
                    }
                    if (field.validity.rangeUnderflow) {
                        field.setCustomValidity(message('range_underflow', field, { min: field.min }));
                        return;
                    }
                    if (field.validity.rangeOverflow) {
                        field.setCustomValidity(message('range_overflow', field, { max: field.max }));
                        return;
                    }
                    if (field.validity.typeMismatch) {
                        field.setCustomValidity(message('type_mismatch', field));
                        return;
                    }
                    if (field.validity.badInput) {
                        field.setCustomValidity(message('bad_input', field));
                        return;
                    }

                    field.setCustomValidity(message('file_type', field));
                };

                document.querySelectorAll('[data-marketplace-validation]').forEach((form) => {
                    const fields = form.querySelectorAll('input, select, textarea');

                    fields.forEach((field) => {
                        field.addEventListener('invalid', () => setMessage(field));
                        field.addEventListener('input', () => setMessage(field));
                        field.addEventListener('change', () => setMessage(field));
                    });

                    form.addEventListener('submit', () => {
                        fields.forEach((field) => setMessage(field));
                    });
                });
            });
        </script>
    @endpush
@endonce
