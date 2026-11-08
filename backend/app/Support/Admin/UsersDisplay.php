<?php

namespace App\Support\Admin;

final class UsersDisplay
{
    public static function role(string $role): string
    {
        return [
            'admin_full' => 'Администратор', 'admin_limited' => 'Сотрудник',
            'manager' => 'Менеджер', 'teacher' => 'Преподаватель',
            'polina' => 'Полина',
            'user_with_purchase' => 'Ученик с покупками', 'user_no_purchase' => 'Зарегистрированный пользователь',
        ][$role] ?? $role;
    }

    public static function date(?string $value, bool $short = false): string
    {
        if (! $value) {
            return 'Не указано';
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return 'Дата недоступна';
        }
        if ($short) {
            return $date->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m.Y');
        }

        return (new \IntlDateFormatter('ru_RU', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'Europe/Moscow'))->format($date) ?: 'Дата недоступна';
    }

    public static function legacyMoney(string $amount, string $currency): string
    {
        if (preg_match('/^-?\d+$/', $amount) !== 1 || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            return 'Сумма недоступна';
        }
        $negative = str_starts_with($amount, '-');
        $digits = ltrim($negative ? substr($amount, 1) : $amount, '0');
        $padded = str_pad($digits, 3, '0', STR_PAD_LEFT);
        $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', substr($padded, 0, -2));

        return ($negative ? '-' : '').$whole.','.substr($padded, -2).' '.$currency;
    }

    public static function label(string $field): string
    {
        return [
            'name' => 'Имя', 'email' => 'Email', 'phone' => 'Телефон', 'birth_date' => 'Дата рождения',
            'gender' => 'Пол', 'school' => 'Учебное заведение', 'study_year' => 'Курс обучения',
            'enrollment_year' => 'Год поступления', 'study_direction' => 'Направление обучения',
            'profession' => 'Профессия', 'interests' => 'Интересы', 'referral_source' => 'Источник знакомства',
            'preferred_contact' => 'Способ связи', 'contact_details' => 'Контакт', 'vk_profile' => 'ВКонтакте',
            'manager_id' => 'Менеджер', 'tags' => 'Метки', 'before' => 'До', 'after' => 'После',
            'access' => 'Покрытие', 'required_atoms_count' => 'Всего уроков', 'owned_atoms_count' => 'Доступно уроков',
            'bonus_balance' => 'Баланс бонусов', 'comment' => 'Комментарий', 'description' => 'Описание',
            'title' => 'Название', 'text' => 'Текст', 'status' => 'Статус', 'created_at' => 'Создано',
            'updated_at' => 'Изменено', 'message' => 'Сообщение', 'code' => 'Код',
        ][$field] ?? (ctype_digit($field) ? 'Запись '.((int) $field + 1) : str_replace('_', ' ', $field));
    }
}
