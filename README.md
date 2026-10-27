# Operations Daily Check System

Internal daily ops for BAYN TECHNOLOGIES.  
**Stack:** Laravel 12 · React (Vite) · MySQL · Telegram bot · Africa/Addis_Ababa

## Flow

1. **Admin** taps *Send morning* (or scheduled time) → bot:  
   `Good morning {name}` — ask for daily tasks as `1) … 2) …`
2. Member sends the list → **plan locks**. No edits unless admin unlocks.
3. **Admin** taps *Send evening* → bot:  
   `Good evening {name}` — ✅ / ❌ per task; ❌ requires a roadblock explanation.  
   Cannot finish until every task is answered.
4. Bot: `Good night {name}`

Attendance check-in/out is removed. Web is for Team Today + Admin.

## Setup

```bash
# Backend
cd backend
composer install
# set DB + TELEGRAM_BOT_TOKEN in .env
php artisan migrate --seed
php artisan serve

# Frontend
cd frontend
npm install
npm run dev

# Telegram (local)
php artisan telegram:poll

# Auto morning/evening times
php artisan schedule:work
```

### Demo users (password: `password`)

| Email | Role |
|---|---|
| manager@bayn.local | Manager |
| hana@bayn.local | Member |
| abebe@bayn.local | Member |
| sara@bayn.local | Member |
| yonas@bayn.local | Member |

### Link Telegram

1. Create a bot with [@BotFather](https://t.me/BotFather), put token in `backend/.env` as `TELEGRAM_BOT_TOKEN=`
2. Member opens the bot and sends: `/start hana@bayn.local`
3. Admin → **Admin** page → *Send morning now*

## Admin API

- `POST /api/v1/admin/bot/morning`
- `POST /api/v1/admin/bot/evening`
- `POST /api/v1/admin/days/{id}/unlock` `{ "note": "…" }`
- `PUT /api/v1/settings` `{ "reminder_times": { "morning": "09:00", "evening": "17:30" } }`
