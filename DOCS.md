# Divi Pro Engagement v2.1.3 — Техническая документация

Данный документ является полным техническим справочником архитектуры, API, мета-полей, шорткодов и настроек плагина `Divi Pro Engagement` (v2.1.3). Предназначен для разработчиков и ИИ-ассистентов для работы с проектом без необходимости полного сканирования кодовой базы.

---

## 1. Обзор и Архитектура

### 1.1 Назначение плагина
`Divi Pro Engagement` — высокопроизводительный модульный комбайн для WordPress 6.5+ и темы/конструктора Divi 5. Разработан с акцентом на совместимость с агрессивными системами полностраничного кеширования (FlyingPress, WP Rocket, LiteSpeed), нулевое влияние на показатели Core Web Vitals (LCP, TBT, CLS) и строгое соблюдение стандартов безопасности (PHP 8.1+, `declare(strict_types=1)`).

Полностью заменяет и консолидирует в едином легковесном решении следующие сторонние плагины:
1. **Nextend Social Login / Super Socializer** — заменен на нативный модуль REST OAuth 2.0 (Google, Telegram, Discord, Facebook, X/Twitter) с генерацией безопасных локальных сессий.
2. **kk Star Ratings / WP-PostRatings** — заменен на модуль `RatingEngine` (5-звездочный рейтинг и Лайки/Дизлайки, Schema.org JSON-LD AggregateRating, кольцевой буфер защиты от накрутки). Включает встроенный импортер мета-полей из kk Star Ratings.
3. **wpDiscuz / Supercharge Divi Comments** — заменен на асинхронный модуль `CommentsEngine` (AJAX-отправка, AJAX-сортировка без перезагрузки, древовидные ответы до 5 уровней, антиспам Honeypot + Cloudflare Turnstile, плавающий nudge-виджет).
4. **Leira Letter Avatar / Simple Local Avatars** — заменен на встроенный генератор векторных SVG Data-URI аватарок по первой букве имени (0 внешних HTTP-запросов к Gravatar) с поддержкой аватарок из соцсетей.
5. **WPS Hide Login** — заменен на модуль `CustomLogin` (маршрутизация нестандартного URL авторизации, блокировка прямого доступа к `wp-login.php` и 404-ответ для гостей, отключение админ-бара и `/wp-admin/` для подписчиков).
6. **Change Mail Sender** — заменен на системные фильтры `wp_mail_from` / `wp_mail_from_name` (приоритет 99) для кастомизации имени и адреса отправителя системных писем.
7. **Отключение CPT "Projects" темы Divi** — выгрузка неиспользуемого типа записей `project` из админ-меню и запросов WordPress.

---

### 1.2 Дерево файлов и структура классов
Базовое пространство имен: `DiviProEngagement\` (PSR-4 автозагрузка).

```text
divi-pro-engagement/
├── divi-pro-engagement.php        # Главный файл плагина, константы, PSR-4 автолоадер, dpc_get_option()
├── AGENTS.md                      # Правила разработки, регламент версий и карта файлов
├── DOCS.md                        # Полная техническая спецификация (текущий файл)
├── README.md                      # Базовое описание репозитория
├── assets/
│   ├── css/
│   │   ├── admin.css              # Стили панели настроек плагина в wp-admin
│   │   └── frontend.css           # Критические фронтенд-стили (кнопки соцсетей, рейтинг, комментарии, темная/светлая тема)
│   └── js/
│       ├── admin.js               # Скрипты админ-панели (переключение табов, валидация)
│       └── frontend.js            # Клиентская логика (AJAX, syncClientVotes, авто-обновление nonce, Telegram on-demand)
└── includes/
    ├── Core/
    │   ├── CustomLogin.php        # DiviProEngagement\Core\CustomLogin (кастомный URL входа, 404, ограничение /wp-admin/)
    │   ├── Init.php               # DiviProEngagement\Core\Init (хуки, ассеты, dpc_refresh_nonce, Mail Sender, откл. CPT project, хелперы)
    │   ├── Settings.php           # DiviProEngagement\Core\Settings (страница настроек, валидация, импорт kk Star Ratings)
    │   ├── Shortcodes.php         # DiviProEngagement\Core\Shortcodes (регистрация [dpc_social_login] и [dpc_post_rating])
    │   └── Updater.php            # DiviProEngagement\Core\Updater (автообновление с GitHub через transient WordPress)
    └── Modules/
        ├── Auth/
        │   ├── AuthManager.php    # DiviProEngagement\Modules\Auth\AuthManager (REST OAuth роуты, сессии, SVG аватарки)
        │   └── Providers/
        │       ├── DiscordProvider.php   # Провайдер Discord OAuth 2.0 (проверка verified email)
        │       ├── FacebookProvider.php  # Провайдер Facebook Login Graph API
        │       ├── GoogleProvider.php    # Провайдер Google Identity OAuth 2.0 (проверка email_verified)
        │       ├── TelegramProvider.php  # Провайдер Telegram Login Widget (HMAC SHA-256 + защита от replay-атак)
        │       └── TwitterProvider.php   # Провайдер X / Twitter OAuth 2.0 PKCE (S256)
        ├── Comments/
        │   ├── CommentsEngine.php        # DiviProEngagement\Modules\Comments\CommentsEngine (AJAX комментарии, N+1 оптимизация)
        │   └── comments-template.php     # Переопределенный шаблон комментариев (совместим с иерархией Divi 5)
        └── PostRating/
            └── RatingEngine.php          # DiviProEngagement\Modules\PostRating\RatingEngine (звезды/лайки, Schema.org)
```

---

### 1.3 Глобальные константы и хелперы

#### Константы (определены в `divi-pro-engagement.php`):
- `DPC_VERSION` — текущая версия плагина (строка, например `'2.1.0'`).
- `DPC_FILE` — абсолютный путь к главному файлу плагина (`__FILE__`).
- `DPC_PATH` — абсолютный путь к каталогу плагина с закрывающим слешем (`plugin_dir_path( __FILE__ )`).
- `DPC_URL` — базовый URL плагина с закрывающим слешем (`plugin_dir_url( __FILE__ )`).
- `DPC_BASENAME` — базовое имя плагина (`plugin_basename( __FILE__ )`, e.g. `'divi-pro-engagement/divi-pro-engagement.php'`).

#### Функция `dpc_get_option()`:
```php
function dpc_get_option( string $key, mixed $default = null ): mixed
```
- Получает значение опции из массива `dpc_settings` таблицы `wp_options`.
- **Производительность:** использует локальный статический in-memory кеш `$cached_settings` на время жизненного цикла одного PHP-процесса.
- **Инвалидация:** автоматически сбрасывает статический кеш при вызове хуков `update_option_dpc_settings`, `add_option_dpc_settings`, `delete_option_dpc_settings`.

#### Статические методы класса `DiviProEngagement\Core\Init`:
- `Init::is_visual_builder_active(): bool`
  Проверяет, открыт ли в данный момент визуальный билдер Divi 5 (`et_core_is_fb_enabled()`). Предотвращает поломку конструктора и выполнение фронтенд-скриптов при редактировании страниц.
- `Init::get_visitor_short_hash(): string`
  Формирует 10-символьный MD5-хэш из комбинации IP-адреса посетителя (`REMOTE_ADDR`) и строки User-Agent. Используется для дедупликации голосов гостей без хранения персональных данных.
- `Init::append_visitor_hash( mixed $hashes, int $limit = 200 ): array`
  Добавляет короткий хэш текущего посетителя в циклический буфер (массив в `postmeta` или `commentmeta`) с автоматической обрезкой до `$limit` последних записей. Предотвращает неконтролируемое раздувание базы данных при голосованиях ботов.

---

## 2. Справочник Шорткодов и Динамических Полей

### 2.1 Шорткод `[dpc_social_login]`
Выводит блок кнопок авторизации через включенные социальные сети, либо выпадающее меню профиля (`User Avatar Dropdown`), если пользователь уже авторизован.

#### Поддерживаемые атрибуты:
| Атрибут | Тип | Возможные значения | По умолчанию | Описание |
| :--- | :--- | :--- | :--- | :--- |
| `style` | `string` | `inline`, `icon`, `grid` | `''` | Универсальный стиль: если передан, переопределяет и десктоп, и мобильный вид. |
| `style_desktop` | `string` | `inline`, `icon`, `grid` | Значение из опций (`'inline'`) | Расположение кнопок на экранах $\ge 768\text{px}$. |
| `style_mobile` | `string` | `inline`, `icon`, `grid` | Значение из опций (`'icon'`) | Расположение кнопок на экранах $< 768\text{px}$. |
| `skin` | `string` | `brand`, `monochrome`, `outline` | Значение из опций (`'brand'`) | Цветовая тема кнопок (фирменные цвета, монохром или контурные). |

Примеры:
```text
[dpc_social_login]
[dpc_social_login skin="outline" style="icon"]
[dpc_social_login style_desktop="inline" style_mobile="grid"]
```

---

### 2.2 Шорткод `[dpc_post_rating]`
Выводит интерактивный блок оценки текущей записи (звезды 1–5 или Лайк / Дизлайк в зависимости от глобальной настройки `rating_type`).

#### Поддерживаемые атрибуты:
| Атрибут | Тип | По умолчанию | Описание |
| :--- | :--- | :--- | :--- |
| `post_id` | `int` | `0` | ID конкретного поста. Если `0`, автоматически берется текущий ID записи из цикла (`get_the_ID()`). |

Пример:
```text
[dpc_post_rating]
[dpc_post_rating post_id="142"]
```

---

### 2.3 Кастомные Мета-поля (Postmeta, Commentmeta, Usermeta)

Плагин использует исключительно стандартные таблицы WordPress (`wp_postmeta`, `wp_commentmeta`, `wp_usermeta`) без создания дополнительных SQL-таблиц.

#### 1. Таблица `wp_postmeta` (Оценка публикаций)
| Ключ мета-поля | Тип данных | Пример значения | Назначение / Применение в Divi 5 |
| :--- | :--- | :--- | :--- |
| `_dpc_rating_avg` | `float` (1 знак) | `4.8` | Средний балл (1.0–5.0). Идеально для сортировки популярных постов в Divi Loop Builder. |
| `_dpc_rating_count` | `int` | `42` | Общее число пользователей/гостей, поставивших звезды. |
| `_dpc_rating_sum` | `int` | `201` | Суммарное количество выставленных звезд (для пересчета средней оценки). |
| `_dpc_likes_count` | `int` | `156` | Количество лайков поста (в режиме `rating_type = likes`). |
| `_dpc_dislikes_count` | `int` | `3` | Количество дизлайков поста (в режиме `rating_type = likes`). |
| `_dpc_guest_hashes` | `array<string>` | `['a1b2c3d4e5', ...]` | Циклический буфер последних 200 хэшей гостей (защита от накрутки рейтинга). |

#### 2. Таблица `wp_commentmeta` (Реакции на комментарии)
| Ключ мета-поля | Тип данных | Пример значения | Описание |
| :--- | :--- | :--- | :--- |
| `_dpc_comment_likes` | `int` | `14` | Количество лайков («палец вверх») комментария. |
| `_dpc_comment_dislikes`| `int` | `1` | Количество дизлайков («палец вниз») комментария. |
| `_dpc_comment_guest_hashes` | `array<string>` | `['c7d8e9f012', ...]` | Циклический буфер последних 200 хэшей гостей для оценки комментария. |

#### 3. Таблица `wp_usermeta` (Профиль и голоса пользователя)
| Ключ мета-поля | Тип данных | Пример значения | Описание |
| :--- | :--- | :--- | :--- |
| `_dpc_avatar_url` | `string` | `https://lh3.google...` | Прямая ссылка на аватарку из социальной сети (перехватывается в `get_avatar`). |
| `_dpc_google_id` | `string` | `10928374615243...` | Уникальный Google Subject ID пользователя. |
| `_dpc_telegram_id` | `string` | `48291048` | Уникальный Telegram ID пользователя. |
| `_dpc_discord_id` | `string` | `39281749201928...` | Уникальный Discord Snowflake ID. |
| `_dpc_facebook_id` | `string` | `981273918273...` | Уникальный Facebook Scoped User ID. |
| `_dpc_twitter_id` | `string` | `1293847291827...` | Уникальный Twitter/X User ID. |
| `_dpc_post_votes` | `array<int, mixed>`| `[142 => 5, 145 => 'like']`| Карта голосов за посты: `[ post_id => value ]` (с лимитом до 500 постов). |
| `_dpc_comment_votes`| `array<int, string>`| `[88 => 'up', 91 => 'down']`| Карта голосов за комментарии: `[ comment_id => 'up'/'down' ]` (лимит 500). |

---

### 2.4 Использование в Divi 5 Loop Builder и WP_Query

#### Пример 1: Вывод рейтинга в Dynamic Content Divi 5
В любом текстовом модуле Divi 5 внутри Loop Archive / Post Loop выберите **Dynamic Content** $\rightarrow$ **Custom Field Name** $\rightarrow$ укажите `_dpc_rating_avg` или `_dpc_likes_count`.

#### Пример 2: Сортировка постов по популярности (`WP_Query` / Meta Query)
Сортировка по среднему рейтингу записей (от высшего к низшему):
```php
$args = [
    'post_type'      => 'post',
    'post_status'    => 'publish',
    'posts_per_page' => 10,
    'meta_key'       => '_dpc_rating_avg',
    'orderby'        => 'meta_value_num',
    'order'          => 'DESC',
    'meta_query'     => [
        [
            'key'     => '_dpc_rating_avg',
            'value'   => 0,
            'compare' => '>',
            'type'    => 'DECIMAL(3,1)',
        ],
    ],
];
$popular_posts = new \WP_Query( $args );
```

Сортировка постов по наибольшему числу лайков:
```php
$args = [
    'post_type'      => 'post',
    'posts_per_page' => 10,
    'meta_key'       => '_dpc_likes_count',
    'orderby'        => 'meta_value_num',
    'order'          => 'DESC',
];
```

---

## 3. Справочник Настроек (`dpc_settings` в `wp_options`)

Все настройки плагина сериализуются в единую запись `dpc_settings` таблицы `wp_options`. Доступ осуществляется через `dpc_get_option( 'key', $default )`.

### Вкладка 1: Основные модули (`general`)
| Ключ опции | Тип | Значение по умолчанию | Описание |
| :--- | :--- | :--- | :--- |
| `enable_social_auth` | `bool` | `0` (выключено) | Включение модуля OAuth авторизации через соцсети. |
| `enable_post_rating` | `bool` | `0` (выключено) | Включение модуля голосования за записи (звезды/лайки). |
| `enable_comments` | `bool` | `0` (выключено) | Активация кастомного AJAX-шаблона комментариев и древовидных ответов. |
| `enable_custom_login` | `bool` | `0` (выключено) | Включение защиты и подмены URL страницы авторизации `wp-login.php`. |
| `custom_login_slug` | `string` | `''` | Слаг кастомного адреса входа (например: `secret-gate`). |
| `hide_admin_bar` | `bool` | `0` (выключено) | Скрытие панели Admin Bar и блокировка `/wp-admin/` для роли `subscriber`. |
| `mail_from_name` | `string` | `''` | Имя отправителя исходящих системных писем WordPress (Sender Name). |
| `mail_from_email` | `string` | `''` | Email отправителя системных писем (`sanitize_email`, защита от `wordpress@www.domain`). |
| `disable_divi_projects` | `bool` | `0` (выключено) | Полное отключение неиспользуемого стандартного CPT `project` (Проекты) темы Divi. |

### Вкладка 2: Соц. Авторизация (`auth`)
| Ключ опции | Тип | Значение по умолчанию | Описание |
| :--- | :--- | :--- | :--- |
| `auth_show_in_comments` | `bool` | `1` (включено) | Выводить блок кнопок авторизации над формой комментариев. |
| `auth_select_account` | `bool` | `0` (выключено) | Принуждать пользователя выбирать аккаунт при каждом входе (`prompt=select_account`). |
| `auth_auto_link_email` | `bool` | `1` (включено) | Автопривязка соцсети к существующему пользователю при совпадении verified email. |
| `auth_disable_admin` | `bool` | `1` (включено) | Запрет входа через соцсети для пользователей с ролью Администратора (`manage_options`). |
| `auth_default_role` | `string` | `'subscriber'` | Роль для вновь регистрируемых посетителей (администратор запрещен). |
| `auth_shortcode_style_desktop` | `string` | `'inline'` | Десктопный стиль шорткода: `inline`, `icon`, `grid`. |
| `auth_shortcode_style_mobile` | `string` | `'icon'` | Мобильный стиль шорткода: `inline`, `icon`, `grid`. |
| `auth_shortcode_skin` | `string` | `'brand'` | Цветовая гамма шорткода: `brand`, `monochrome`, `outline`. |
| `auth_comments_style_desktop` | `string` | `'inline'` | Десктопный стиль в комментариях: `inline`, `icon`, `grid`. |
| `auth_comments_style_mobile` | `string` | `'icon'` | Мобильный стиль в комментариях: `inline`, `icon`, `grid`. |
| `auth_comments_skin` | `string` | `'brand'` | Цветовая гамма кнопок в комментариях: `brand`, `monochrome`, `outline`. |
| `auth_size_desktop` | `int` | `44` ($\text{px}$, 24–100) | Высота кнопок на экранах $\ge 768\text{px}$. |
| `auth_size_mobile` | `int` | `40` ($\text{px}$, 24–100) | Высота кнопок на экранах $< 768\text{px}$. |
| `auth_google_enable` | `bool` | `0` | Включение авторизации через Google Identity. |
| `auth_google_client_id` | `string` | `''` | Google OAuth Client ID. |
| `auth_google_client_secret` | `string` | `''` | Google OAuth Client Secret. |
| `auth_telegram_enable` | `bool` | `0` | Включение авторизации через Telegram Widget. |
| `auth_telegram_bot_username`| `string` | `''` | Юзернейм Telegram-бота (без символа `@`). |
| `auth_telegram_bot_token` | `string` | `''` | Токен Telegram-бота (для HMAC-SHA256 валидации подписи). |
| `auth_facebook_enable` | `bool` | `0` | Включение авторизации через Facebook Login. |
| `auth_facebook_app_id` | `string` | `''` | Facebook App ID. |
| `auth_facebook_app_secret` | `string` | `''` | Facebook App Secret. |
| `auth_discord_enable` | `bool` | `0` | Включение авторизации через Discord. |
| `auth_discord_client_id` | `string` | `''` | Discord Client ID. |
| `auth_discord_client_secret`| `string` | `''` | Discord Client Secret. |
| `auth_twitter_enable` | `bool` | `0` | Включение авторизации через X (Twitter) OAuth 2.0. |
| `auth_twitter_client_id` | `string` | `''` | Twitter OAuth 2.0 Client ID. |
| `auth_twitter_client_secret`| `string` | `''` | Twitter OAuth 2.0 Client Secret. |

### Вкладка 3: Оценка записей (`rating`)
| Ключ опции | Тип | Значение по умолчанию | Описание |
| :--- | :--- | :--- | :--- |
| `rating_type` | `string` | `'stars'` | Тип голосования: `'stars'` (1–5 звезд) или `'likes'` (Лайк/Дизлайк). |
| `rating_access` | `string` | `'all'` | Кто может голосовать: `'all'` (все посетители) или `'logged_in'` (только авторизованные). |
| `rating_position` | `string` | `'after'` | Позиция авто-вставки в контент: `'after'`, `'before'` или `'shortcode'` (ручная вставка). |
| `rating_schema_enable` | `bool` | `1` (включено) | Генерация разметки Schema.org `AggregateRating` (JSON-LD) в `<head>`. |

### Вкладка 4: Комментарии и Антиспам (`comments`)
| Ключ опции | Тип | Значение по умолчанию | Описание |
| :--- | :--- | :--- | :--- |
| `comments_skin` | `string` | `'light'` | Тема оформления списка комментариев: `'light'` или `'dark'`. |
| `comments_form_position` | `string` | `'bottom'` | Расположение основной формы: `'bottom'` (под комментариями) или `'top'` (над списком). |
| `comments_custom_font_size`| `string` | `''` | Кастомный CSS-размер шрифта (например: `15px`, `0.95rem`). Валидируется по белому списку символов. |
| `comments_vote_access` | `string` | `'all'` | Доступ к лайкам комментариев: `'all'` (все) или `'logged_in'` (только зарегистрированные). |
| `comments_nudge_enable` | `bool` | `0` (выключено) | Отображение плавающей кнопки «Оставить комментарий» при прокрутке. |
| `comments_nudge_position` | `string` | `'bottom-right'` | Положение плавающего виджета: `'bottom-right'` или `'bottom-left'`. |
| `comments_nudge_delay` | `int` | `15` (сек, 1–300) | Задержка появления плавающей кнопки после открытия статьи. |
| `comments_nudge_text` | `string` | `''` | Кастомная надпись на кнопке (если пусто — используется мультиязычный словарь). |
| `antispam_honeypot` | `bool` | `1` (включено) | Невидимое проверочное поле-ловушка (`dpc_hp_check`) против спам-ботов. |
| `antispam_turnstile_enable`| `bool` | `0` (выключено) | Проверка невидимой капчи Cloudflare Turnstile для гостей. |
| `turnstile_site_key` | `string` | `''` | Открытый Site Key Cloudflare Turnstile. |
| `turnstile_secret_key` | `string` | `''` | Секретный Secret Key Cloudflare Turnstile (для серверной проверки `siteverify`). |

---

## 4. API, Эндпоинты и Безопасность

### 4.1 REST API: OAuth Маршруты и Callback URL
Базовый Namespace: `/wp-json/dpc/v1`

| Маршрут | HTTP-метод | Обработчик | Описание |
| :--- | :--- | :--- | :--- |
| `/wp-json/dpc/v1/auth/{provider}/start` | `GET` | `AuthManager::handle_auth_start` | Старт OAuth-процесса: генерация `state` (TTL 10 мин), сохранение `redirect_to`, редирект в соцсеть. |
| `/wp-json/dpc/v1/auth/{provider}` | `GET` | `AuthManager::handle_auth_callback` | OAuth Callback: валидация `state`, обмен кода на access token, верификация профиля и вход/создание WP-аккаунта. |

#### Точные Callback URL для настройки в личных кабинетах разработчиков:
*(Замените `https://example.com` на реальный домен сайта)*
- **Google Cloud Console:**  
  `Authorized redirect URIs`: `https://example.com/wp-json/dpc/v1/auth/google`  
  *Scopes:* `openid email profile`
- **Discord Developer Portal:**  
  `Redirects`: `https://example.com/wp-json/dpc/v1/auth/discord`  
  *Scopes:* `identify email`
- **Telegram BotFather:**  
  Команда `/setdomain` для вашего бота $\rightarrow$ указать базовый домен: `example.com`  
  *Callback эндпоинт в плагине:* `https://example.com/wp-json/dpc/v1/auth/telegram`
- **Facebook Developers:**  
  `Valid OAuth Redirect URIs`: `https://example.com/wp-json/dpc/v1/auth/facebook`
- **Twitter (X) Developer Portal:**  
  `Callback URI / Redirect URL`: `https://example.com/wp-json/dpc/v1/auth/twitter`  
  *Type:* Web App, OAuth 2.0 with PKCE (S256).

---

### 4.2 AJAX-эндпоинты (`admin-ajax.php`)
Все AJAX-запросы используют единый nonce `dpc_frontend_nonce` (передается в объекте `dpcFrontend.nonce`).

| Действие (`action`) | Доступность | Назначение | Параметры POST |
| :--- | :--- | :--- | :--- |
| `dpc_rate_post` | Авториз. и Гости | Голосование за пост (звезды 1–5 или like/dislike) | `post_id`, `value` (`1..5` или `'like'`/`'dislike'`), `nonce` |
| `dpc_vote_comment` | Авториз. и Гости | Лайк/дизлайк комментария | `comment_id`, `vote` (`'up'` или `'down'`), `nonce` |
| `dpc_submit_comment`| Авториз. и Гости | AJAX отправка комментария или ответа | `comment_post_ID`, `comment_parent`, `comment`, `author`, `dpc_hp_check`, `cf-turnstile-response`, `nonce` |
| `dpc_load_comments` | Публичный | Фильтрация и сортировка комментариев | `post_id`, `sort` (`'oldest'`, `'newest'`, `'popular'`), `lang` |
| `dpc_refresh_nonce` | Публичный | Легковесный запрос свежего `dpc_frontend_nonce` | `action=dpc_refresh_nonce` (возвращает `{ "nonce": "..." }`) |

---

### 4.3 Механизмы работы с агрессивным кешем страниц (FlyingPress / Varnish)
1. **Чистый HTML-кеш без чужих голосов:**  
   Серверный рендеринг виджетов рейтинга и комментариев не читает `$_COOKIE` для гостей. В HTML попадают чистые кнопки без классов `is-voted` / `is-active`, что исключает ситуацию, когда голос первого посетителя запекается в HTML-кеш страницы для всех остальных пользователей.
2. **Клиентская синхронизация `syncClientVotes()`:**  
   Скрипт `frontend.js` при событии DOM ready считывает `document.cookie` и локальное хранилище `localStorage` (`dpc_rated_<id>` и `dpc_c_voted_<id>`), мгновенно подсвечивая активные звезды и лайки только на устройстве текущего пользователя.
3. **Бесшовное обновление Nonce (`ajaxWithNonceRetry`):**  
   Если страница отдана из старого кеша и WordPress возвращает ошибку 403 / `invalid_nonce`, `frontend.js` прозрачно вызывает действие `dpc_refresh_nonce`, получает новый nonce, обновляет `dpcFrontend.nonce` и повторяет оригинальный запрос без вывода ошибки пользователю.
4. **Неблокирующее голосование гостей:**  
   Для публичных действий гостей (`dpc_rate_post`, `dpc_vote_comment`, `dpc_load_comments`) проверка nonce не прерывает запрос фатальной ошибкой при устаревшем страничном кеше. Подлинность и защита от накрутки гарантируются связкой `Init::get_visitor_short_hash()` и кольцевыми буферами `_dpc_guest_hashes`.
5. **Динамическая подгрузка внешних скриптов (Core Web Vitals):**  
   - Скрипт `https://telegram.org/js/telegram-widget.js?22` полностью исключен из первоначальной очереди загрузки страницы. Функция `ensureTelegramWidgetLoaded()` вставляет его в DOM динамически только в момент клика по кнопке Telegram.
   - Скрипт Cloudflare Turnstile подключается исключительно с атрибутом `defer` и только на страницах записей, где открыты комментарии (`is_singular() && comments_open()`).
6. **Сохранение позиции скролла при входе/выходе (`dpc_scroll_restore`):**  
   При клике на любую кнопку социальной авторизации (`.dpc-social-btn`) или кнопку выхода (`.dpc-logout-btn`) в `sessionStorage` сохраняются точные координаты скролла (`scrollY`), текущий URL-путь (`path`), метка времени (`timestamp`) и признак нахождения в блоке комментариев (`inComments`). При возврате на страницу скролл мгновенно восстанавливается на сохраненную высоту (с дополнительным циклом через `requestAnimationFrame` и таймаут для компенсации догрузки стилей/шрифтов Divi 5), а при входе из комментариев фокус ввода автоматически переводится в `textarea`. Также при клике динамически актуализируется параметр `redirect_to` под текущий адрес страницы без хешей на случай устаревшего HTML-кеша FlyingPress.

---

### 4.4 CSS-переменные и Стилизация

#### CSS-переменные:
```css
/* Кнопки социальной авторизации */
.dpc-social-login {
    --dpc-size-desktop: 44px;      /* Высота кнопок на ПК */
    --dpc-size-mobile: 40px;       /* Высота кнопок на мобильных */
    --dpc-current-size: var(--dpc-size-desktop);
}

/* Область комментариев */
.dpc-comments-area {
    --dpc-custom-font-size: 15px;  /* Задается в админке или переопределяется в CSS */
    --dpc-c-root-bg: #ffffff;      /* Фон комментариев 1-го уровня */
    --dpc-c-reply-bg: #f8fafc;     /* Фон ответов (nested) */
    --dpc-c-surface: #f8fafc;      /* Фон карточек и форм */
    --dpc-c-border: #e2e8f0;       /* Цвет рамок */
    --dpc-c-tree-line: #cbd5e1;     /* Цвет линий древовидных веток */
    --dpc-c-text: #0f172a;         /* Основной цвет текста */
    --dpc-c-muted: #64748b;        /* Второстепенный текст и мета-данные */
    --dpc-c-input-bg: #ffffff;     /* Фон полей ввода */
    --dpc-c-accent: #2563eb;       /* Акцентный цвет (кнопки, ссылки) */
    --dpc-c-accent-hover: #1d4ed8; /* Ховер акцентного цвета */
}

/* Темная тема (активируется классом .dpc-comments-skin-dark) */
.dpc-comments-area.dpc-comments-skin-dark {
    --dpc-c-root-bg: #0f172a;
    --dpc-c-reply-bg: #1e293b;
    --dpc-c-surface: #1e293b;
    --dpc-c-border: #334155;
    --dpc-c-tree-line: #475569;
    --dpc-c-text: #f8fafc;
    --dpc-c-muted: #94a3b8;
    --dpc-c-input-bg: #0f172a;
    --dpc-c-accent: #3b82f6;
    --dpc-c-accent-hover: #60a5fa;
}
```

#### Ключевые селекторы для быстрой кастомизации:
- `.dpc-social-login` — корневой контейнер кнопок авторизации (`.dpc-desktop-style-inline`, `.dpc-skin-brand`, `.dpc-skin-outline`).
- `.dpc-post-rating` — контейнер рейтинга статьи (`.dpc-post-rating--stars`, `.dpc-post-rating--likes`, `.is-voted`, `.is-locked`). Внешние вертикальные отступы обнулены (`margin: 0;`), отступами управляет Divi 5.
- `.dpc-star-btn` — кнопка звезды (`.is-active` для подсвеченных звезд).
- `.dpc-like-btn` / `.dpc-like-up` / `.dpc-like-down` — кнопки лайка/дизлайка поста.
- `.dpc-comments-area` / `#dpc-comments` — контейнер блока комментариев. Верхние отступы обнулены (`margin-top: 0; padding-top: 0;`).
- `.dpc-comments-header` — шапка комментариев со счетчиком и кнопками сортировки (верхние отступы обнулены).
- `.dpc-comments-header__count` / `.dpc-comments-count` — счетчик комментариев с мультиязычной подписью (`comments_title`) и числом `.dpc-comments-count__num`.
- `.dpc-comment-form` — форма добавления комментария (начинается с имени для гостей или с поля ввода для авторизованных).
- `.dpc-comment-form__footer` — нижний flex-ряд формы:
  - `.dpc-comment-form__footer-left` — социальные кнопки входа для гостей / аватар с выпадающим меню для авторизованных.
  - `.dpc-comment-form__footer-right` — капча Cloudflare Turnstile, кнопка «Отменить ответ» и кнопка «Отправить» (`margin-left: auto;`).
- `.dpc-comment-card` — карточка отдельного комментария.
- `.dpc-badge-author` — бейдж «Автор» для комментариев создателя записи или администратора.
- `.dpc-c-vote-btn` — кнопки оценки комментариев (`.dpc-c-vote-up`, `.dpc-c-vote-down`).
- `.dpc-nudge-btn` — плавающая кнопка быстрого перехода к комментариям.
