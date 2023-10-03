<?php
ini_set('display_errors', 1);

require_once(dirname(__FILE__) . "/../../../vendor/autoload.php");
require_once(dirname(__FILE__) . "/gptreply.php");
require_once(dirname(__FILE__) . "/../flexMessages/checkInputNewQuestion.php");
require_once(dirname(__FILE__) . "/../flexMessages/CreateFlexMessage.php");
require_once(dirname(__FILE__) . "/../utils/sendEmail.php");
require_once(dirname(__FILE__) . "/line.php");
require_once(dirname(__FILE__) . "/discussions.php");
require_once(dirname(__FILE__) . "/questions.php");


use LINE\LINEBot;
use LINE\LINEBot\HTTPClient\CurlHTTPClient;
use LINE\LINEBot\MessageBuilder\MultiMessageBuilder;
use LINE\LINEBot\MessageBuilder\TextMessageBuilder;
use LINE\LINEBot\MessageBuilder\StickerMessageBuilder;
use LINE\LINEBot\Exception\InvalidEventRequestException;
use LINE\LINEBot\Exception\InvalidSignatureException;
use \LINE\LINEBot\TemplateActionBuilder\PostbackTemplateActionBuilder;
use \LINE\LINEBot\MessageBuilder\TemplateBuilder\ConfirmTemplateBuilder;
use \LINE\LINEBot\MessageBuilder\TemplateMessageBuilder;
use \LINE\LINEBot\MessageBuilder\FlexMessageBuilder;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . "/../../../"); //.envを読み込む
$dotenv->load();


/**
 * LINE botのWebhookコントローラー
 */
class BotController
{
  public $code = 200;
  public $url;
  public $httpClient;
  public $bot;
  public $requestBody;

  function __construct()
  {
    $this->url = (empty($_SERVER['HTTPS']) ? 'http://' : 'https://') . $_SERVER['HTTP_HOST'] . mb_substr($_SERVER['SCRIPT_NAME'], 0, -9) . basename(__FILE__, ".php") . "/";
    $this->httpClient = new CurlHTTPClient(getenv("LINE_ACCESS_TOKEN"));
    $this->bot = new LINEBot($this->httpClient, ['channelSecret' => getenv("LINE_CHANNEL_SECRET")]);
    $this->requestBody = file_get_contents('php://input');
  }

  /**************************************************************************** */
  /**
   * GETメソッド (動作確認用)
   * @param array $args
   * @return array レスポンス
   */
  public function get($args)
  {
    $this->code = 200;
    return "I'm alive!!";
  }

  /**************************************************************************** */
  /**
   * POSTメソッド
   * @param array $args
   * @return array レスポンス
   */
  public function post($path)
  {
    switch ($path[0]) {
        // LINEbot の応答処理
      case "webhook":
        // 署名の存在確認
        if (empty($_SERVER['HTTP_X_LINE_SIGNATURE'])) {
          $this->code = 400;
          return ["error" => [
            "type" => "signature_not_found"
          ]];
        }
        // レスポンスデータを作成
        $responseData = $this->webhook($this->requestBody, $_SERVER['HTTP_X_LINE_SIGNATURE']);

        // レスポンスデータをJSONエンコード
        $jsonResponse = json_encode($responseData);
        error_log(print_r($jsonResponse , true) . "\n", 3, dirname(__FILE__) . '/debugA.log');

        // 署名検証のため，request body をそのまま渡す
        return $jsonResponse;

        // LINEbot のプッシュ通知処理
      case "push":
        // request bodyをUTF-8にエンコード -> PHPの連想配列に変換
        $req = json_decode(mb_convert_encoding($this->requestBody, "UTF8", "ASCII,JIS,UTF-8,EUC-JP,SJIS-WIN"), true);
        return $this->push($req);

      default:
        $this->code = 404;
        return ["error" => ["type" => "path_not_found"]];
    }
  }

  private function webhook($requestBody, $signature)
  {
    $response = null;

    try {
      // LINEBotが受信したイベントオブジェクトを受け取る
      $events = $this->bot->parseEventRequest($requestBody, $signature);
      // セッション管理用に学生ごとのセッションIDを取得（学生のユーザIDを使用）
      $jsonData = json_decode($requestBody, true);
      $studentId = $jsonData['events'][0]['source']['userId'];
    } catch (InvalidSignatureException $e) {
      $this->code = 400;
      error_log(print_r($e, true) . "\n", 3, dirname(__FILE__) . '/debug.log');
      return ["error" => ["type" => "Invalid_signature"]];
    } catch (InvalidEventRequestException $e) {
      $this->code = 400;
      error_log(print_r($e, true) . "\n", 3, dirname(__FILE__) . '/debug.log');
      return ["error" => ["type" => "Invalid_event_request"]];
    } catch (Exception $e) {
      $this->code = 400;
      error_log(print_r($e, true) . "\n", 3, dirname(__FILE__) . '/debug.log');
      return ["error" => ["type" => "unknown_error"]];
    }

    foreach ($events as $event) {
      $replyToken = $event->getReplyToken(); // ReplyTokenの取得
      $eventType = $event->getType();

      if ($eventType === 'message') {
        // メッセージイベント
        $replyMessages = $this->handleMessageEvent($event, $studentId);
        $response = $this->bot->replyMessage($replyToken, $replyMessages);
      } else if ($eventType === 'follow') {
        // フォローイベント(友達追加・ブロック解除時)
        $replyMessages = $this->handleFollowEvent($event);
        $response = $this->bot->replyMessage($replyToken, $replyMessages);
      } else if ($eventType === 'postback') {
        // ボタンなど押した場合
        // continue;
        $responseMessage = new MultiMessageBuilder();
        // $responseMessage->add(new TextMessageBuilder('エラー'));
        // ユーザが選択したボタンの情報を取得
        $postbackData = $jsonData['events'][0]['postback']['data'];
        // error_log(print_r($postbackData, true) . "\n", 3, dirname(__FILE__) . '/debug.log');

        // ユーザの選択に応じて返答を生成
        if ($postbackData === 'action=confirm&response=q_yes') {
          $responseMessage->add(new TextMessageBuilder('ありがとうございます！新しい質問があればいつでも聞いてくださいね！😊'));
          $lineController = new LineController();
          $lineController->insertConversation($studentId, "student", "text", "質問解決", "question-finish", "2");
          // $lineController->insertConversation($studentId, "bot", "text", $responseMessage, "question-finish", "2");
          $lineController = new LineController();
          $userQuestion = $lineController -> getUserInputQuestion($studentId);
          $lineController = new LineController();
          $botAnswer = $lineController -> getBotInputQuestion($studentId);
          $questionsController = new QuestionsController();
          $questionResponse = $questionsController -> insertQuestionData($studentId, "1", $userQuestion);
          $questionAdd = $questionsController -> updateAnswer($questionResponse["questionIndex"], "0", $userQuestion, $studentId, $botAnswer, "test");
        } elseif ($postbackData === 'action=confirm&response=q_no') {
          $lineController = new LineController();
          $lineController->insertConversation($studentId, "bot", "text", "先生に送信してみよう！", "question-bot", "2");
          error_log(print_r($studentId , true) . "\n", 3, dirname(__FILE__) . '/debugA.log');
          $lineController = new LineController();
          $userQuestion = $lineController -> getUserInputQuestion($studentId);
          error_log(print_r($userQuestion, true) . "\n", 3, dirname(__FILE__) . '/debugA.log');
          $flexMessage = requestAnswerFlexMessageBuilder($userQuestion);
          // error_log(print_r($flexMessage, true) . "\n", 3, dirname(__FILE__) . '/debug.log');
          // $flexMessageBuilder = new FlexMessageBuilder("この質問で間違いないですか？", $flexMessage);
          $responseMessage->add($flexMessage);
        } elseif ($postbackData === 'action=confirm&response=t_yes') {
          $responseMessage->add(new TextMessageBuilder('先生に質問しています！返信が来るまでもう少し待ってね🧐'));
        } elseif ($postbackData === 'action=confirm&response=t_no') {
          $responseMessage->add(new TextMessageBuilder('わかりました！新しい質問があればいつでも聞いてくださいね！😊'));
          $lineController = new LineController();
          $lineController->insertConversation($studentId, "student", "text", "質問を終了", "question-finish", "2");
          $lineController->insertConversation($studentId, "bot", "text", $responseMessage, "question-finish", "2");
        } elseif ($postbackData === 'キャンセル') {
          $responseMessage->add(new TextMessageBuilder('わかりました！新しい質問があればいつでも聞いてくださいね！😊'));
          $lineController = new LineController();
          $lineController->insertConversation($studentId, "student", "text", "キャンセル", "question-finish", "2");
          $lineController->insertConversation($studentId, "bot", "text", $responseMessage, "question-finish", "2");
        } else {
          // ボタン以外のPostbackデータには反応しない
          http_response_code(400);
          exit;
        }
        $response = $this->bot->replyMessage($replyToken, $responseMessage);
      } else {
        continue;
      }

      // 送信失敗の場合はサーバーのエラーログへ
      if (!$response->isSucceeded()) {
        error_log('Failed! ' . $response->getHTTPStatus() . ' ' . $response->getRawBody(), 3, dirname(__FILE__) . '/debug.log');
      }

      $this->code = $response->getHTTPStatus();
      return $response->getRawBody();
    }
  }

  public function handleFollowEvent($event)
  {
    $replyMessages = new MultiMessageBuilder();
    $replyMessages->add(new TextMessageBuilder("友達追加ありがとう！"));
    // $replyMessages->add(new StickerMessageBuilder(1, 1));
    $flexMessage = FollowFlexMessageBuilder();
    $replyMessages->add($flexMessage);
    return $replyMessages;
  }

  public function handleMessageEvent($event, $studentId)
  {
    // 手動で設定
    $type = "A";
    $number = 1;
    $lineController = new LineController();

    $replyMessages = new MultiMessageBuilder();
    // メッセージの取得
    $userMessage = $event->getText();
    // セッションIDを元にセッションの状態を取得
    $sessionId = 'session_' . $studentId;
    $sessionData = isset($_SESSION[$sessionId]) ? $_SESSION[$sessionId] : array();
    // error_log(print_r($sessionId, true) . "\n", 3, dirname(__FILE__) . '/debug_session.log');
    // error_log(print_r($userMessage, true) . "\n", 3, dirname(__FILE__) . '/debug_message.log');
    $userId = $event->getuserId();
    $messageType = "text";
    $contextName = "";
    $lifespanCount = "2"; //適当に設定してる


    // セッションの状態に応じて適切な処理を行う
    if ($userMessage === '質問があります') {
      // 新しいセッションを開始
      error_log("ほげほげ". "\n", 3, dirname(__FILE__).'/debugA.log');
      $contextName = "question-start";
      $lineController->insertConversation($userId, "student", $messageType, $userMessage, $contextName, $lifespanCount);
      // error_log(print_r("質問開始", true) . "\n", 3, dirname(__FILE__) . '/debug.log');
      $_SESSION[$sessionId] = array('state' => 'initial');
      // 「質問があります」という学生の最初のメッセージに対して返答を生成
      // $generatedText = 'こんにちは！データサイエンス入門' + $type + '第' + $number + '回講義の質問を受付中です！質問を具体的に書いてもらえる？😊';
      $generatedText = 'こんにちは！データサイエンス入門の質問を受付中です！質問を具体的に書いてもらえる？😊';
      // error_log(print_r($generatedText, true) . "\n", 3, dirname(__FILE__) . '/debug.log');
      $lineController->insertConversation($userId, "bot", $messageType, $generatedText, $contextName, $lifespanCount);
      $replyMessages->add(new TextMessageBuilder($generatedText));
            // error_log(print_r($replyMessages, true) . "\n", 3, dirname(__FILE__) . '/debugA.log');
    } elseif ($userMessage === '質問を終了') {
      // セッション終了
      $contextName = "question-finish";
      $lineController->insertConversation($userId, "student", $messageType, $userMessage, $contextName, $lifespanCount);
      unset($_SESSION[$sessionId]);
      $generatedText = '質問対応を終了しました。新しい質問があればいつでも聞いてくださいね！😊';
      $lineController->insertConversation($userId, "bot", $messageType, $generatedText, $contextName, $lifespanCount);
      $replyMessages->add(new TextMessageBuilder($generatedText));
    } elseif ($userMessage === 'キャンセル') {
      // フレックスメッセージの処理
      $contextName = "question-finish";
      $lineController->insertConversation($userId, "student", $messageType, $userMessage, $contextName, $lifespanCount);
      unset($_SESSION[$sessionId]);
      $generatedText = 'わかりました。また質問があればいつでも聞いてくださいね！😊';
      $lineController->insertConversation($userId, "bot", $messageType, $generatedText, $contextName, $lifespanCount);
      $replyMessages->add(new TextMessageBuilder($generatedText));
    } elseif ($userMessage === '質問を送信') {
      // フレックスメッセージの処理
      $contextName = "throw-teacher";
      $generatedText = '質問を先生に送信したよ！回答まで時間がかかるかもしれないけど待っててね！';
      $lineController->insertConversation($userId, "bot", $messageType, $generatedText, $contextName, $lifespanCount);
      $replyMessages->add(new TextMessageBuilder($generatedText));

      $lineController = new LineController();
      $userQuestion = $lineController -> getUserInputQuestion($userId);
      $questionsController = new QuestionsController();
      $questionResponse = $questionsController -> insertQuestionData($userId, "1", $userQuestion);
      error_log(print_r($questionResponse["questionIndex"], true) . "\n", 3, dirname(__FILE__) . '/debugA.log');

      // 教員・TAに通知
      $user_question_log = "userId";
      $response = "sample";
      // include(dirname(__FILE__)."/../api/v2/controllers/questions.php");
      // $questionsController = new QuestionsController();
      // $response = $questionsController -> insertQuestionData($userId, $user_question_log);
      $payload = array('message' => $userQuestion, 'index' => $questionResponse["questionIndex"]);
      // error_log(print_r($payload, true) . "\n", 3, dirname(__FILE__) . '/debug_message.log');
      //なにこれecho callbackToSusanPro("question", $payload);
      // データベース登録の方でメール送ってたecho sendEmailToInstructors("newQuestion", $userQuestion, $questionResponse["QAIndex"]);
      // $replyMessages->add(sentQuestionFlexMessage($response["QAIndex"]));
      $replyMessages->add(sentQuestionFlexMessage($questionResponse["questionIndex"]));
      // error_log(print_r($replyMessages, true) . "\n", 3, dirname(__FILE__) . '/debug_message.log');
    } elseif ($userMessage === "システムの使い方を教えて") {
      $setMessageToDB = "使い方を確認";
      $flexMessage = howToUseFlexMessage();
      $replyMessages->add($flexMessage);
    }else {
      // 送られたテキストをデータベースに登録
      
      $contextName = "question";
      $lineController->insertConversation($userId, "student", $messageType, $userMessage, $contextName, $lifespanCount);
      // error_log(print_r($event, true) . "\n", 3, dirname(__FILE__) . '/debugevent.log');

      // セッション中の処理（質問に対する応答など）
      // error_log(print_r($event, true) . "\n", 3, dirname(__FILE__) . '/debugA.log');
      $generatedText = makereply($event);
      $lineController->insertConversation($userId, "bot", $messageType, $generatedText, $contextName, $lifespanCount);
      if (preg_match("/先生に聞いてみようか🤔/", $generatedText)) {
        // error_log(print_r($userMessage, true) . "\n", 3, dirname(__FILE__) . '/debug.log');
        $flexMessage = requestAnswerFlexMessageBuilder($userMessage);
        // error_log(print_r($flexMessage, true) . "\n", 3, dirname(__FILE__) . '/debug.log');
        // $flexMessageBuilder = new FlexMessageBuilder("この質問で間違いないですか？", $flexMessage);
        $replyMessages->add($flexMessage);
      } else {
        $replyMessages->add(new TextMessageBuilder($generatedText));
        $yes_confirm = new PostbackTemplateActionBuilder('はい', 'action=confirm&response=q_yes');
        $no_confirm = new PostbackTemplateActionBuilder('いいえ', 'action=confirm&response=q_no');
        $actions = [$yes_confirm, $no_confirm];
        $confirm = new ConfirmTemplateBuilder('質問は解決しましたか？🧐', $actions);
        $confirm_message = new TemplateMessageBuilder('質問は解決しましたか？🧐', $confirm);
        $replyMessages->add($confirm_message);
      };
      // $responseMessage = generateResponse($userMessage);
    }

    // error_log(print_r($replyMessages, true) . "\n", 3, dirname(__FILE__) . '/debug_reply.log');
    // $replyMessages->add(new StickerMessageBuilder(1, 2));
    return $replyMessages;
  }

  public function push($requestBody)
  {
    $response = $this->bot->broadcast(new TextMessageBuilder("ブロードキャスト通知テスト"));
    $this->code = $response->getHTTPStatus();
    return $response->getRawBody();
  }
}
