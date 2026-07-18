<section class="panel">
    @if ($errors->any())
        <div class="alert-message alert-error">
            <strong>Проверьте заполнение формы.</strong>
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="source-form" method="POST" action="{{ $action }}">
        @csrf
        @if ($method !== 'POST')
            @method($method)
        @endif

        <label>
            <span>Название источника</span>
            <input type="text" name="name" value="{{ old('name', $source?->name) }}" required>
        </label>

        <label>
            <span>Тип источника</span>
            <select name="type" id="source-type" required>
                <option value="api" @selected(old('type', $source?->type ?? 'api') === 'api')>API</option>
                <option value="telegram" @selected(old('type', $source?->type) === 'telegram')>Telegram-канал</option>
                <option value="website" @selected(old('type', $source?->type) === 'website')>Сайт</option>
            </select>
        </label>

        <label>
            <span id="source-address-label">URL API</span>
            <input
                type="url"
                name="address"
                id="source-address"
                value="{{ old('address', $source?->address) }}"
                placeholder="https://example.com/api"
                required
            >
            <small id="source-address-help" class="field-help">Используйте HTTPS-ссылку.</small>
        </label>

        <label>
            <span>Чат или канал для публикации</span>
            <input
                type="url"
                name="publication_chat"
                value="{{ old('publication_chat', $source?->publication_chat) }}"
                placeholder="https://t.me/channel_name"
                required
            >
            <small class="field-help">Укажите публичную или пригласительную HTTPS-ссылку Telegram.</small>
        </label>

        <label>
            <span>Интервал проверки, секунд</span>
            <input type="number" name="check_interval" min="1" max="86400" value="{{ old('check_interval', $source?->check_interval ?? 60) }}" required>
            <small class="field-help">Допустимое значение: от 1 секунды до 24 часов.</small>
        </label>

        <div class="form-actions">
            <button class="button button-primary" type="submit">{{ $submitLabel }}</button>
            <a class="button button-secondary" href="{{ route('alerts.sources') }}">Отмена</a>
        </div>
    </form>
</section>

<script>
    const typeField = document.getElementById('source-type');
    const addressLabel = document.getElementById('source-address-label');
    const addressField = document.getElementById('source-address');
    const addressHelp = document.getElementById('source-address-help');

    const fieldOptions = {
        api: {
            label: 'URL API',
            placeholder: 'https://example.com/api',
            help: 'Используйте HTTPS-ссылку API.'
        },
        telegram: {
            label: 'Ссылка на Telegram-канал',
            placeholder: 'https://t.me/channel_name',
            help: 'Используйте публичную HTTPS-ссылку вида https://t.me/channel_name.'
        },
        website: {
            label: 'URL сайта',
            placeholder: 'https://example.com',
            help: 'Используйте HTTPS-ссылку сайта.'
        }
    };

    function updateAddressField() {
        const option = fieldOptions[typeField.value];
        addressLabel.textContent = option.label;
        addressField.placeholder = option.placeholder;
        addressHelp.textContent = option.help;
    }

    typeField.addEventListener('change', updateAddressField);
    updateAddressField();
</script>
