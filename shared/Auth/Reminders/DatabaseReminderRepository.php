<?php

namespace Shared\Auth\Reminders;

use Nova\Database\Connection;
use Nova\Support\Str;

use Carbon\Carbon;


class DatabaseReminderRepository implements ReminderRepositoryInterface
{
    /**
     * The database connection instance.
     *
     * @var \Nova\Database\Connection
     */
    protected $connection;

    /**
     * The reminder database table.
     *
     * @var string
     */
    protected $table;

    /**
     * The hashing key.
     *
     * @var string
     */
    protected $hashKey;

    /**
     * The number of seconds a reminder should last.
     *
     * @var int
     */
    protected $expires;

    /**
     * Create a new reminder repository instance.
     *
     * @param  \Nova\Database\Connection  $connection
     * @param  string  $table
     * @param  string  $hashKey
     * @param  int  $expires
     * @return void
     */
    public function __construct(Connection $connection, $table, $hashKey, $expires = 60)
    {
        $this->table = $table;
        $this->hashKey = $hashKey;
        $this->expires = $expires * 60;
        $this->connection = $connection;
    }

    /**
     * Create a new reminder record and token.
     *
     * @param  \Auth\Reminders\RemindableInterface  $user
     * @return string
     */
    public function create(RemindableInterface $user)
    {
        $email = $user->getReminderEmail();

        $this->deleteExisting($user);

        // We will create a new, random token for the user so that we can e-mail them
        // a safe link to the password reset form. Then we will insert a record in
        // the database so that we can verify the token within the actual reset.
        $token = $this->createNewToken($user);

        $this->getTable()->insert($this->getPayload($email, $token));

        return $token;
    }

    /**
     * Delete all existing reset tokens from the database.
     *
     * @param  \Auth\Reminders\RemindableInterface  $user
     * @return int
     */
    protected function deleteExisting(RemindableInterface $user)
    {
        return $this->getTable()->where('email', $user->getReminderEmail())->delete();
    }

    /**
     * Build the record payload for the table.
     *
     * @param  string  $email
     * @param  string  $token
     * @return array
     */
    protected function getPayload($email, $token)
    {
        return array('email' => $email, 'token' => $token, 'created_at' => new Carbon);
    }

    /**
     * Determine if a reminder record exists and is valid.
     *
     * @param  \Auth\Reminders\RemindableInterface  $user
     * @param  string  $token
     * @return bool
     */
    public function exists(RemindableInterface $user, $token)
    {
        $email = $user->getReminderEmail();

        $reminder = (array) $this->getTable()->where('email', $email)->where('token', $token)->first();

        return $reminder && ! $this->reminderExpired($reminder);
    }

    /**
     * Determine if the reminder has expired.
     *
     * @param  array  $reminder
     * @return bool
     */
    protected function reminderExpired($reminder)
    {
        $createdPlusHour = strtotime($reminder['created_at']) + $this->expires;

        return $createdPlusHour < $this->getCurrentTime();
    }

    /**
     * Get the current UNIX timestamp.
     *
     * @return int
     */
    protected function getCurrentTime()
    {
        return time();
    }

    /**
     * Delete a reminder record by token.
     *
     * @param  string  $token
     * @return void
     */
    public function delete($token)
    {
        $this->getTable()->where('token', $token)->delete();
    }

    /**
     * Delete expired reminders.
     *
     * @return void
     */
    public function deleteExpired()
    {
        $expired = Carbon::now()->subSeconds($this->expires);

        $this->getTable()->where('created_at', '<', $expired)->delete();
    }

    /**
     * Create a new token for the user.
     *
     * @param  \Auth\Reminders\RemindableInterface  $user
     * @return string
     */
    public function createNewToken(RemindableInterface $user)
    {
        $tokens = $this->getTable()->lists('token');

        do {
            $token = Str::random(100);
        }
        while (in_array($token, $tokens));

        return $token;
    }

    /**
     * Begin a new database query against the table.
     *
     * @return \Nova\Database\Query\Builder
     */
    protected function getTable()
    {
        return $this->connection->table($this->table);
    }

    /**
     * Get the database connection instance.
     *
     * @return \Nova\Database\Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

}