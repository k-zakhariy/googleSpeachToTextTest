<?php

require 'vendor/autoload.php';

use Google\Cloud\Speech\V1\RecognitionMetadata;
use Google\Cloud\Speech\V1\SpeechClient;
use Google\Cloud\Speech\V1\RecognitionAudio;
use Google\Cloud\Speech\V1\RecognitionConfig;
use Google\Cloud\Speech\V1\RecognitionConfig\AudioEncoding;
# Imports the Google Cloud client library
use Google\Cloud\Speech\V1\SpeechContext;
use Google\Cloud\Storage\StorageClient;

use Google\Cloud\Speech\V1\StreamingRecognitionConfig;
use Google\Cloud\Speech\V1\StreamingRecognizeRequest;
use Google\CRC32\PHP;
use Google\Protobuf\Internal\GPBDecodeException;
use Google\Protobuf\Internal\GPBUtil;

function seconds2SRT($seconds)
{
    $hours = 0;
    $milliseconds = str_replace("0.", '', $seconds - floor($seconds));

    if ($seconds > 3600) {
        $hours = floor($seconds / 3600);
    }
    $seconds = $seconds % 3600;


    return str_pad($hours, 2, '0', STR_PAD_LEFT)
        . gmdate(':i:s', $seconds)
        . ($milliseconds ? ",$milliseconds" : '');
}


/**
 * @param $value Google\Protobuf\Duration
 * @return string
 * @throws GPBDecodeException
 */
function formatTimestamp($value)
{
    if (bccomp($value->getSeconds(), "253402300800") != -1) {
        throw new GPBDecodeException("Duration number too large.");
    }
    if (bccomp($value->getSeconds(), "-62135596801") != 1) {
        throw new GPBDecodeException("Duration number too small.");
    }
    $nanoseconds = GPBUtil::getNanosecondsForTimestamp($value->getNanos());
    $nanoseconds = $nanoseconds ? "." . $nanoseconds : ".000";
    $date = new \DateTime('@' . $value->getSeconds(), new \DateTimeZone("UTC"));
    return $date->format("H:i:s" . $nanoseconds);
}

/**
 * Transcribe an audio file using Google Cloud Speech API
 * Example:
 * ```
 * transcribe_async_gcs('your-bucket-name', 'audiofile.wav');
 * ```.
 *
 * @param string $bucketName The Cloud Storage bucket name.
 * @param string $objectName The Cloud Storage object name.
 * @param string $languageCode The Cloud Storage
 *     be recognized. Accepts BCP-47 (e.g., `"en-US"`, `"es-ES"`).
 * @param array $options configuration options.
 *
 * @return string the text transcription
 */
function transcribe_async_gcs($fileUri, $filename)
{
    // change these variables
    $encoding = AudioEncoding::LINEAR16;
    $sampleRateHertz = 48000;
    $languageCode = 'ru-RU';

    // set string as audio content
    $audio = (new RecognitionAudio())
        ->setUri($fileUri);

    $speechContext = new SpeechContext(['phrases' => [
        'Проповедь',
        'Бог',
        'Евангелие',
    ]]);


    $a = RecognitionMetadata\InteractionType::PROFESSIONALLY_PRODUCED;
    $b = RecognitionMetadata\OriginalMediaType::VIDEO;

    $metadata = new RecognitionMetadata();
    $metadata->setInteractionType(RecognitionMetadata\InteractionType::PROFESSIONALLY_PRODUCED);
    $metadata->setOriginalMediaType(RecognitionMetadata\OriginalMediaType::VIDEO);


    // set config
    $config = (new RecognitionConfig())
//        ->setModel('video')
        ->setMetadata($metadata)
        ->setEncoding($encoding)
//        ->setSampleRateHertz($sampleRateHertz)
        ->setAudioChannelCount(2)
        ->setEnableAutomaticPunctuation(true)
        ->setSpeechContexts(array($speechContext))
        ->setEnableWordTimeOffsets(true)
        ->setLanguageCode($languageCode);

    // create the speech client
    $client = new SpeechClient([
        'credentials' => json_decode(file_get_contents(__DIR__ . '/config.json'), true)
    ]);

    // create the asyncronous recognize operation
    $operation = $client->longRunningRecognize($config, $audio);
    $operation->pollUntilComplete();

    if ($operation->operationSucceeded()) {
        $response = $operation->getResult();

        // each result is for a consecutive portion of the audio. iterate
        // through them to get the transcripts for the entire audio file.
        /* foreach ($response->getResults() as $result) {
             $alternatives = $result->getAlternatives();
             $mostLikely = $alternatives[0];
             $transcript = $mostLikely->getTranscript();
             $confidence = $mostLikely->getConfidence();
             printf('Transcript: %s' . PHP_EOL, $transcript);
             printf('Confidence: %s' . PHP_EOL, $confidence);
         }*/

        $counter = 1;
        $string = '';
        foreach ($response->getResults() as $result) {
            $alternatives = $result->getAlternatives();
            $mostLikely = $alternatives[0];
            $transcript = $mostLikely->getTranscript();
            $confidence = $mostLikely->getConfidence();

//            printf('Confidence: %s' . PHP_EOL, $confidence);
            $startTranscript = null;
            $endTranscript = null;
            foreach ($mostLikely->getWords() as $wordInfo) {
                /** @var Google\Protobuf\Duration $startTime */
                $startTime = $wordInfo->getStartTime();
                $endTime = $wordInfo->getEndTime();

//                printf('  Word: %s (start: %s, end: %s)' . PHP_EOL, $wordInfo->getWord(), formatTimestamp($startTime), formatTimestamp($endTime));

//                print_r($startTime->getNanos());
//                echo PHP_EOL;
                /*              printf('  Word: %s (start: %s, end: %s)' . PHP_EOL,
                                  $wordInfo->getWord(),
                                  $startTime->serializeToJsonString(),
                                  $endTime->serializeToJsonString());*/

                if (empty($startTranscript)) {
                    $startTranscript = $startTime;
                }

                $endTranscript = $endTime;

            }

            $string .= $counter . PHP_EOL;
            $string .= formatTimestamp($startTranscript) . ' --> ' . formatTimestamp($endTranscript) . PHP_EOL;
            $string .= trim($transcript) . PHP_EOL;
            $string .= PHP_EOL;

            $counter++;
        }

        if (!empty($string)) {
            file_put_contents(__DIR__ . '/results/' . $filename . time() . '.srt', $string);
        }
    } else {
        print_r($operation->getError());
    }

    $client->close();
}

/**
 * Upload a file.
 *
 * @param string $bucketName the name of your Google Cloud bucket.
 * @param string $objectName the name of the object.
 * @param string $source the path to the file to upload.
 *
 * @return Psr\Http\Message\StreamInterface
 */
function upload_object($bucketName, $objectName, $source)
{
    $storage = new StorageClient([
        'projectId' => 'vifania-1244',
        'keyFilePath' => __DIR__ . '/config.json' # the key
    ]);
    $file = fopen($source, 'r');
    $bucket = $storage->bucket($bucketName);
    $object = $bucket->upload($file, [
        'name' => $objectName
    ]);
    printf('Uploaded %s to gs://%s/%s' . PHP_EOL, basename($source), $bucketName, $objectName);
}

transcribe_async_gcs('gs://vifania/audioSample.wav', 'audioSample');
// upload_object('vifania', 'audioSample.wav', __DIR__.'/audio/audioSample.wav');



