<?php

/**
 * @file classes/tasks/SendPollClosingReminder.php
 */

namespace APP\plugins\generic\groupReview\classes\tasks;

use APP\core\Application;
use APP\facades\Repo;
use APP\journal\JournalDAO;
use APP\notification\NotificationManager;
use APP\plugins\generic\groupReview\classes\GroupReviewService;
use APP\plugins\generic\groupReview\classes\mail\GroupReviewPollClosing;
use APP\plugins\generic\groupReview\classes\notification\Notification as GroupReviewNotification;
use APP\plugins\generic\groupReview\GroupReviewPlugin;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PKP\db\DAORegistry;
use PKP\plugins\PluginSettingsDAO;
use PKP\scheduledTask\ScheduledTask;
use Throwable;

class SendPollClosingReminder extends ScheduledTask
{
    protected function executeActions()
    {
        $service = new GroupReviewService();
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');  /** @var PluginSettingsDAO $pluginSettingsDao */
        $journalDao = DAORegistry::getDAO('JournalDAO');  /** @var JournalDAO $journalDao */
        $request = Application::get()->getRequest();
        $now = Carbon::now('UTC');
        $notificationManager = new NotificationManager();

        $polls = DB::table('group_review_sessions')
            ->where('status', GroupReviewService::STATUS_OPEN)
            ->orderBy('session_id')
            ->get();

        foreach ($polls as $row) {
            $contextId = (int) $row->context_id;
            $pollId = (int) $row->session_id;
            $deadline = Carbon::parse($row->deadline_utc, 'UTC');
            if (!$pluginSettingsDao->getSetting($contextId, 'groupreviewplugin', 'enabled')) {
                continue;
            }
            $context = $journalDao->getById($contextId);
            if (!$context || !$service->isEnabled($context)) {
                continue;
            }

            if ($deadline->lte($now)) {
                DB::table('group_review_sessions')
                    ->where('session_id', $pollId)
                    ->where('status', GroupReviewService::STATUS_OPEN)
                    ->update([
                        'status' => GroupReviewService::STATUS_EXPIRED,
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                continue;
            }

            if (!(bool) $row->send_reminder
                || $now->lt($deadline->copy()->subHours((int) $row->reminder_before_hours))) {
                continue;
            }

            $bundle = $service->getBundle($contextId, $pollId);
            if (!$bundle) {
                continue;
            }

            $pollUrl = $request->getDispatcher()->url(
                $request,
                Application::ROUTE_PAGE,
                $context->getPath(),
                'groupReview',
                'availability',
                null,
                ['pollId' => $pollId]
            );
            $deadlineDisplay = $service->formatUtc($row->deadline_utc, $row->timezone);
            $submissionTitle = $bundle['submission'] ? $bundle['submission']->getLocalizedTitle() : '';
            $leaderName = $bundle['leader'] ? $bundle['leader']->getFullName() : '';

            foreach ($service->memberUsers($bundle, null, false) as $user) {
                if (DB::table('group_review_reminder_log')
                    ->where('session_id', $pollId)
                    ->where('user_id', $user->getId())
                    ->exists()) {
                    continue;
                }

                try {
                    $claimed = DB::table('group_review_reminder_log')->insertOrIgnore([
                        'session_id' => $pollId,
                        'user_id' => (int) $user->getId(),
                        'sent_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    if (!$claimed) {
                        continue;
                    }

                    $template = Repo::emailTemplate()->getByKey(
                        $contextId,
                        GroupReviewPollClosing::getEmailTemplateKey()
                    );
                    if (!$template) {
                        throw new \RuntimeException('Group Review reminder email template is missing.');
                    }

                    $mailable = new GroupReviewPollClosing(
                        $context,
                        $user->getFullName(),
                        $pollUrl,
                        $submissionTitle,
                        $deadlineDisplay,
                        $leaderName
                    );
                    $mailable->from($context->getContactEmail(), $context->getContactName())
                        ->to($user->getEmail(), $user->getFullName())
                        ->subject($template->getLocalizedData('subject'))
                        ->body($template->getLocalizedData('body'));
                    Mail::send($mailable);
                    $notificationManager->createNotification(
                        $request,
                        (int) $user->getId(),
                        GroupReviewNotification::NOTIFICATION_TYPE_POLL_CLOSING,
                        $contextId,
                        GroupReviewPlugin::ASSOC_TYPE_POLL,
                        $pollId,
                        GroupReviewNotification::NOTIFICATION_LEVEL_TASK
                    );
                } catch (Throwable $e) {
                    DB::table('group_review_reminder_log')
                        ->where('session_id', $pollId)
                        ->where('user_id', $user->getId())
                        ->delete();
                    error_log('Group Review reminder failed: ' . $e->getMessage());
                }
            }
        }

        return true;
    }
}
