<section class="worker-notifications" data-worker-notifications data-csrf="<?= htmlspecialchars((string) $_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
  <div class="worker-notifications-grid">
    <article class="panel notification-settings">
      <div class="notification-heading"><div><span class="eyebrow">УВЕДОМЛЕНИЯ</span><h2>Telegram-оповещения</h2><p>Ошибки, зависание и восстановление worker’ов.</p></div></div>
      <div class="notification-state inactive" data-notification-status><i></i><div><strong>Загрузка…</strong><span>Получение настроек.</span></div></div>
      <form class="notification-form" data-notification-form>
        <label class="notification-switch"><span>Включить уведомления</span><input type="checkbox" name="enabled" value="1"></label>
        <label>Bot Token<input class="input" type="password" name="bot_token" autocomplete="new-password" placeholder="123456789:AA..."></label>
        <label>Chat ID<input class="input" type="text" name="chat_id" inputmode="numeric" placeholder="-1001234567890"></label>
        <label>Повтор одинаковой ошибки, секунд<input class="input" type="number" name="cooldown_seconds" min="60" max="86400" step="60" value="900"></label>
        <div class="notification-actions"><button class="button primary" type="submit" data-notification-save>Сохранить</button><button class="button" type="button" data-notification-test>Отправить тест</button></div>
      </form>
    </article>
    <article class="panel notification-journal">
      <div class="notification-heading"><div><span class="eyebrow">ЖУРНАЛ</span><h2>Последние события</h2><p>Отправленные, подавленные и неуспешные уведомления.</p></div></div>
      <div class="notification-journal-list" data-notification-journal><div class="notification-empty">Загрузка журнала…</div></div>
    </article>
  </div>
</section>
