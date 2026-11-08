<?php

namespace App\Services\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

final class SupportVoice
{
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid() || $file->getSize() <= 0 || $file->getSize() > config('support.voice_max_bytes')) {
            $this->invalid('Размер записи должен быть больше нуля и не превышать 10 МиБ.');
        }
        $path = $file->getRealPath();
        $inventory = $this->probe(['-show_streams', '-show_format'], $path);
        $streams = $inventory['streams'] ?? [];
        $stream = $streams[0] ?? [];
        $format = $inventory['format']['format_name'] ?? '';
        $mime = match (true) {
            $format === 'matroska,webm' && ($stream['codec_name'] ?? '') === 'opus' && str_contains(file_get_contents($path, false, null, 0, 4096), "\x42\x82\x84webm") => 'audio/webm',
            $format === 'mov,mp4,m4a,3gp,3g2,mj2' && ($stream['codec_name'] ?? '') === 'aac' && in_array(trim($inventory['format']['tags']['major_brand'] ?? ''), ['isom', 'iso2', 'iso5', 'iso6', 'mp41', 'mp42', 'M4A', 'dash'], true) => 'audio/mp4',
            default => null,
        };
        if (count($streams) !== 1 || ($stream['codec_type'] ?? '') !== 'audio' || ! $mime || ! is_numeric($stream['sample_rate'] ?? null) || (float) $stream['sample_rate'] <= 0) {
            $this->invalid('Допустима одна аудиодорожка WebM/Opus или MP4/AAC.');
        }
        $decoded = $this->probe(['-threads', '1', '-select_streams', 'a:0', '-show_frames', '-show_entries', 'frame=media_type,pts_time,best_effort_timestamp_time,nb_samples'], $path);
        $start = INF;
        $end = -INF;
        $samplesDuration = 0.0;
        foreach ($decoded['frames'] ?? [] as $frame) {
            $timestamp = $frame['best_effort_timestamp_time'] ?? $frame['pts_time'] ?? null;
            $samples = $frame['nb_samples'] ?? null;
            if (($frame['media_type'] ?? '') !== 'audio' || ! is_numeric($timestamp) || ! is_finite((float) $timestamp) || ! is_numeric($samples) || $samples <= 0) {
                $this->invalid('Не удалось прочитать аудиозапись.');
            }
            $duration = $samples / (float) $stream['sample_rate'];
            $start = min($start, (float) $timestamp);
            $end = max($end, (float) $timestamp + $duration);
            $samplesDuration += $duration;
        }
        if (! is_finite($start) || $samplesDuration <= 0 || max($end - $start, $samplesDuration) > config('support.voice_max_seconds')) {
            $this->invalid('Запись должна содержать аудио длительностью до трёх минут.');
        }

        return ['mime' => $mime, 'bytes' => $file->getSize(), 'hash' => hash_file('sha256', $path)];
    }

    private function probe(array $arguments, string $path): array
    {
        $binary = (new ExecutableFinder)->find(config('support.ffprobe'));
        abort_unless($binary, 503, 'Приём голосовых записей временно недоступен.');
        $process = new Process([$binary, '-v', 'error', ...$arguments, '-of', 'json', $path]);
        $process->setTimeout(15);
        // Keep Process from accumulating its own unbounded copy of decoded frames.
        $process->disableOutput();
        $output = '';
        $errors = '';
        $overflow = false;
        try {
            $process->run(function (string $type, string $chunk) use (&$output, &$errors, &$overflow): void {
                if ($overflow) {
                    return;
                }
                $limit = $type === Process::OUT ? 16 * 1024 * 1024 : 64 * 1024;
                $length = $type === Process::OUT ? strlen($output) : strlen($errors);
                if ($length + strlen($chunk) > $limit) {
                    $overflow = true;
                    throw new \OverflowException('Media probe output limit exceeded');
                }
                if ($type === Process::OUT) {
                    $output .= $chunk;
                } else {
                    $errors .= $chunk;
                }
            });
        } catch (\OverflowException) {
            $process->stop(0);
            $this->invalid('Запись содержит слишком много аудиоданных для проверки.');
        } catch (ProcessStartFailedException) {
            abort(503, 'Приём голосовых записей временно недоступен.');
        } catch (ProcessTimedOutException) {
            $this->invalid('Не удалось проверить аудиозапись за допустимое время.');
        }
        if ($process->getExitCode() === 127) {
            abort(503, 'Приём голосовых записей временно недоступен.');
        }
        if (! $process->isSuccessful() || trim($errors) !== '') {
            $this->invalid('Не удалось прочитать аудиозапись.');
        }
        $result = json_decode($output, true);
        if (! is_array($result)) {
            $this->invalid('Не удалось прочитать аудиозапись.');
        }

        return $result;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['voice' => $message]);
    }
}
