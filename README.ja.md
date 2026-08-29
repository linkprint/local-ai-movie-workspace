# Movie AI Workspace

**自分の AI サーバーを、チーム全員がいつでもどこからでも入れるプライベートな撮影スタジオに。脚本、絵コンテ、ショット生成、素材管理まで、全工程が自分たちのマシンの上で完結し、必要なのはブラウザだけ。**

[English](README.md) · [中文](README.zh-CN.md) · [Español](README.es.md)

![License: MIT](https://img.shields.io/badge/license-MIT-22c55e)
![Self-hosted](https://img.shields.io/badge/セルフホスト-AI-7c3aed)
![Workspace](https://img.shields.io/badge/workspace-Codex%20%2B%20tmux-2563eb)
![Media](https://img.shields.io/badge/video-MiniMax%20H3-f97316)

> **ここから開始：このリポジトリを Codex に渡してください。** Codex がこの README、`AGENTS.md`、リンク先の導入ガイドを読み終えれば、実際の AI サーバーを inventory し、ここで説明するシステム全体の構築を支援できます。対象は予約 Web サイトと管理画面、ブラウザー上の分離 Workspace、PostgreSQL/Redis control plane、個人／企業 AI Plan Session、ローカル／外部モデル routing、GPU Worker、MiniMax H3 workflow、セキュリティ境界、end-to-end acceptance test まで含みます。利用者が実機、正確な network 情報、account、承認済み管理者権限を提供すれば、文書が移植、導入、検証、その後の引き継ぎに必要な architecture と実行可能 contract を Codex に与えます。

> **チームがすでに料金を払っている AI Plan を先に活用し、画像 API へ二重に支払わない。** ChatGPT Pro 20x をすでに持つ 5 人が、月 10,000 枚の背景設定画と絵コンテを生成・編集する本文の例では、この設計により GPT-Image-2 の出力 API 費用だけで **月約 410–1,650 USD**、**年 4,920–19,800 USD** を回避でき、編集時の入力 Token 費用も抑えられます。この数字には API 中心の CLI が使うテキストモデル、Agent 推論、tool、長い context の Token は**まだ含まれていません**。そして最も重要なのは、各制作者が自分の ChatGPT account で CLI にログインすることです。OpenAI 側の Codex activity はその account と data control に従い、local history、project file、credential は各自の分離された persistent Workspace に残ります。詳しい前提と制限は後段に示します。

サーバーはスタジオやマシンルーム、あるいは自宅の片隅に置いたまま。あなたは撮影現場でも自宅でも移動中でも、ブラウザでログインすれば、プロジェクトも AI も生成途中のショットも、置いたままの状態でそこにあります。

## 映像クリエイターの方へ：これは「また別の AI サイト」ではありません

（技術がわからなくても大丈夫。このセクションだけ読めば十分です。）

AI で映像を作っていれば、こんな毎日に覚えがあるはずです。脚本はチャットの窓で、コンセプトアートは別のサイトで、動画は 3 つ目のプラットフォームで順番待ち。素材はクラウドとチャットに散らばったまま。しかも各サービスが別々に課金し、別々に審査する——悪役のモノローグを書いていただけなのに、生成の途中で「規約違反」と止められる。

Movie AI Workspace は別の道を選びます。**他人のプラットフォームに間借りするのではなく、自分のマシンをスタジオにする。** GPU を積んだサーバー 1 台を、あなたとチームのための映像制作プラットフォームに変えるオープンソースプロジェクトです。AI での執筆、画像、動画生成、素材管理が、同じ場所・同じプロジェクトの中で完結します。

### サーバー 1 台が、まるごとスタジオになる

- **閉まらない脚本会議室。** 脚本の分解、台詞の推敲、整合性チェック、翻訳を、自分でデプロイしたモデルが支えます。ホラー、犯罪、戦争、親密な関係——フィクションの通常の題材が、途中で拒否されません。
- **試行錯誤し放題のコンセプト台。** コンセプトアート、絵コンテ、スタイル探索。ローカル画像モデルなら、クレジット残高を気にせず何度でも回せます。
- **ショットを納品する撮影ステージ。** MiniMax H3 の固定 workflow により、テキストからの動画、画像からの動画、先頭/末尾フレーム、参照ベースの生成まで。完成ショットはそのままプロジェクトのメディアライブラリへ。
- **作品ごとに整理されるメディアライブラリ。** 1 作品 1 プロジェクト。脚本、参考資料、生成結果、完成素材が 1 か所にまとまり、散らばりません。

### 数台のマシンで、チーム全員の予定を回す

AI サーバーは高価で、一人一台という体制はまず組めません。本当の課題は「何台買うか」ではなく、**限られた数台で全員の作業を止めないこと**でした。

多くのチームはチャットで「今 2 号機使ってる人いる？」と叫んで解決しています。そして毎度おなじみの展開——二人が同時に走らせて VRAM が破綻する、誰かのジョブが途中で落とされる、誰かがマシンを押さえたまま昼食に出る。そして一番多いのが、実は午後ずっと空いていたサーバーに誰も気づかないことです。

このシステムは GPU を、会議室のように予約できる資源に変えます。

- **空きが一目でわかる。** 各サーバーの予定を日付ごとに確認：誰が、いつまで、どの枠が空いているか。今まさに空いていれば、その場ですぐ開始できます。時刻は各自のタイムゾーンで表示されるので、離れた場所のチームでも時差計算は不要です。
- **押さえた枠は自分のもの。横取りされません。** 同じマシンの同じ枠を二人が確保することは起こりません。PostgreSQL の排他制約がデータベース層で保証しており、アプリ層の紳士協定ではないからです。作業中に落とされる事態は、仕組みとして起こり得ません。
- **延びたら延長できる。** 次の枠がまだ空いていれば、いったん抜けて取り直すのではなく、そのまま延長できます。
- **遊休リソースは自動で戻る。** アイドル状態のワークスペースは自動停止し、プライベートモデルの利用許可も予約の期限切れや未接続に応じて回収されます。ログオフを忘れたせいでマシンが一晩無駄に回り続けることはありません。
- **メンテナンスは先に確保。** ドライバ更新、モデル入れ替え、サービス再起動——メンテナンス枠を宣言すれば、その時間は予約を受け付けません。ノードを *ドレイン* 状態にして、新規予約を止めつつ実行中の作業だけ安全に終わらせることもできます。
- **複数台をまとめて配車。** 予約は具体的な計算ノード単位なので、画像はこの機、動画はあの機、と並行しても衝突しません。
- **誰が何を使ったか記録に残る。** 主要な操作は監査ログに入るため、予定調整も振り返りも記憶やチャット履歴に頼らずに済みます。

### サーバーはラックに、スタジオはポケットに

このシステムは初日から**リモート利用**を前提に設計されています。マシンは置いたまま、あなたはどこからでも仕事ができます。

- **必要なのはブラウザだけ。** ポータルにログインし、枠を予約し、プロジェクトを開けば、いつもの作業台がそのまま現れます。ノート PC でもタブレットでも。
- **切断は中断ではありません。** PC を閉じてもサーバーは働き続け、再ログインすればさっきの現場にそのまま戻れます。AI と交わしていた途中の会話さえ残っています。
- **仕事があなたについてくる。** 昼にスタジオで仕込んだショット生成を、夜は自宅のブラウザで検収。出張中にひらめいたら、つないで台詞を 2 行直す。

> 夕方 5 時、スタジオ。今夜は 3 号機が空いているので 20 時から 23 時を予約し、AI に第 3 シーンの絵コンテをショットリスト化させ、動画ジョブを 2 本投入して PC を閉じ、退勤。
> 夜 10 時、自宅。ブラウザを開くと、2 本のショットはもうライブラリの中。1 本選んで AI に納品基準との照合を任せ、ついでに明日の枠も押さえておく。
> PC は閉じたまま。サーバーは止まらず。作品は前に進み続ける。

### ついでに、おなじみの悩みも消える

- **従量課金の不安から解放。** 台詞の改稿、整合性チェック、翻訳、コンセプト画——1 日に何百回も繰り返す高頻度の作業が自分の GPU で走るので、イテレーションのたびにクレジットが減りません。
- **ComfyUI 設定地獄からの解放。** LoRA や高速化を組み込んだ本格的な画像パイプラインを組むには、HuggingFace や Civitai でモデルを探し、ノードをつなぎ、バージョンを合わせ、エラーが出れば何時間もデバッグ——という夜が続きがちでした。ここではその複雑さを Codex がガイドに沿って組み上げ、管理者の固定 workflow に封じ込めます。クリエイターは呼び出すだけで、ノードには一切触れません。
- **IP の行き先を心配しない。** 未発表の脚本、設定資料、キャスティング参考、ラフカットを、最初から最後まで自分のサーバー内に置けます。第三者のプラットフォームを経由しません。
- **一律審査に振り回されない。** ルールがなくなるのではなく、制作側が決めるようになります。誰が・いつ・何を生成できるかを自分たちの権限と承認の中で管理し、汎用フィルターを映像制作のためのルールに置き換えます。

### 正直なところ

このプロジェクトは作者が 5 日間の空き時間で作ったもので、それなりに粗削りです。細部は磨かれておらず、ドキュメントも育っている途中で、別のハードウェアへ移すには実際の移植作業が必要です。完成した製品ではありませんし、勝手にインストールされることもありません。

それでも、私たち自身の小さなチームの現実的な問題は解決しました——数台の AI サーバーで、全員がぶつからず、遊ばせず、ハードと同じ部屋にいなくても仕事を回すこと。状況が似ているなら、これは少なくとも「すでに動いていて、手を入れられる出発点」です。

### 技術がわからない場合は？

構築には技術担当のパートナーが必要です。あるいはこのプロジェクト自身が推奨するように、Codex や Claude のような AI アシスタントにリポジトリ内の完全なガイドを読ませて構築させることもできます。導入後、クリエイターが日常的に触れるのはブラウザのポータルと作業台だけ。以下の技術セクションはデプロイ担当者に渡してください。それでは、撮影開始です。

---

**ここから先はエンジニアと運用者向けです：自分たちの AI サーバーを予約し、永続的な Codex ワークスペースを開き、プライベートモデルをチーム向けの安全な映像制作スタジオに。**

Movie AI Workspace は、1台または複数の AI マシンを所有するチーム向けのオープンソース制御基盤です。予約ポータル、分離されたプロジェクト、永続化された AI プランの認証状態、プライベート言語モデル、固定された画像・動画ワークフローを統合し、GPU ホストのシェルやプロバイダーキーを一般ユーザーに渡しません。

サーバーを予約し、プロジェクトに入り、同じ tmux セッションへ再接続し、Codex に作業を計画させ、制限付き `movie-ai` CLI から MiniMax H3 動画を生成できます。

## 解決すること

- **チャットで GPU の順番を決めない。** 予約、メンテナンス時間、PostgreSQL 制約が実行権を管理します。
- **自分の AI プランを使う。** 個人の Codex 認証は分離され、管理された会社認証は許可されたオペレーターだけに提供できます。
- **`/model` からプライベートモデルを使う。** Qwen 3.8 27B と DeepSeek V4 Flash のデプロイ別名は予約連動 Broker を経由します。
- **再現可能な映像制作。** MiniMax H3 の skill、固定 workflow、メディアライブラリ、成果物検証を Workspace イメージに同梱します。
- **切断後も続行。** ブラウザ端末、tmux、永続ボリュームが文脈を維持します。
- **正直にスケール。** 現行版は中央 Portal と単一実行ノードを実装。計算ノードを追加する手順はインストールガイド §8 に、マルチノードのデータモデル（ノード単位の排他制約、ノード登録とヘルスチェック）はコードとして収録しています。

## 主なレイヤー

| レイヤー | 役割 |
| --- | --- |
| Portal | Laravel/Filament、TOTP、予約、プロジェクト、管理、メディア |
| Workspace | 強化済み Codex、tmux、永続プロジェクトと認証 |
| Model Router | 通常の Codex 経路を保ち、承認済み私有モデルだけを転送 |
| AI Broker | 予約権限を検証し、言語・画像・動画契約を制限 |
| Media Adapter | 承認済み MiniMax H3・画像 workflow に接続 |
| Host Control | Unix Socket 経由の固定 GPU preflight とサービス操作 |
| AI 引き継ぎ | `AGENTS.md`、`CLAUDE.md`、管理 skill、手順書、テスト |

```text
予約 -> プロジェクト -> 個人/会社 AI 認証 -> ブラウザ tmux
     -> Codex/Claude が計画 -> 私有モデルまたは movie-ai
     -> 固定 Broker workflow -> 検証済み成果物
```

## モデル

- ホスト型 Codex モデルは入室時に選んだ Codex 認証を使います。
- `qwen3.8-27b-uncensored` は設定可能なプライベート Qwen の別名です。
- `deepseek-v4-flash-0731` は設定可能なプライベート/外部 DeepSeek の別名です。
- Z-Image-Turbo と Hunyuan のローカル画像契約を含みます。
- MiniMax H3 は管理者所有 workflow により T2VA、I2VA、FL2VA、L2VA、ネイティブ Ref2VA を提供します。

モデルウェイトは配布しません。「uncensored」は運用者が用意するエンドポイント別名であり、ライセンス、安全方針、適法な利用はデプロイ担当者の責任です。

## セルフホスト uncensored モデルが映像制作を変える理由

Codex を自社管理のモデルと GPU の前に置くと、単なるチャットではなく制作全体の**プロダクション・ブレイン**になります。作品を分解して計画を維持し、適切な skill を読み込み、ショットを準備し、画像や MiniMax H3 workflow を呼び出し、成果物まで検証します。同じターミナルから `/model` で運用者提供の uncensored `deepseek-v4-flash-0731` エンドポイントや `qwen3.8-27b-uncensored` に切り替えられ、プロジェクトを離れたりモデルポートや key を利用者に公開したりする必要がありません。

実際の映像制作では、次の違いが生まれます。

- **脚本会議を止めない。** 正当なフィクションにはホラー、犯罪、戦争、政治風刺、親密な関係、悪役の台詞、身体変容などの成人向け題材も含まれます。スタジオ管理のモデルなら、制作途中の拒否、トーンの自動的な無難化、遠回しな言い換えの繰り返しを減らせます。
- **未公開 IP をスタジオ内に保つ。** 脚本、設定資料、キャスティング参考、絵コンテ、ラフカット、クライアント案を自社ネットワーク内に置けます。メンバーは provider key や LAN endpoint ではなく、予約連動 Broker を通じて利用します。
- **モデルを制作スタッフのように演出する。** ウェイト、量子化、コンテキスト長、system prompt、LoRA、sampling、更新時期を運用者が選べます。脚本と連続性に強いモデル、ショット推論に強いモデルを使い分け、ホスト型 Codex は計画、実装、tool 利用、引き継ぎを担当できます。
- **反復を安価で再現可能にする。** 台詞の改稿、翻訳、ショット差分、連続性確認を、自社 GPU のキャパシティとして扱えます。永続プロジェクト、tmux、skill、固定 workflow、seed、成果物検証が生成方法を残します。
- **会話から完成メディアまでつなぐ。** 単なるモデルのチャット UI ではありません。同じ管理された Session が素材を確認し、映像 skill を呼び出し、制限付き H3 job を投入し、検証済み動画をプロジェクトライブラリへ戻します。

映画制作者にとって重要なのは、uncensored モデルが単に「回答する」ことではありません。同じ私有の創造的な頭脳が、企画から最終ショットまで同じプロジェクト、ツール、参考素材、skill、人間の承認につながり続けることです。**Uncensored は無統制という意味ではありません。**第三者の汎用フィルターを、スタジオ自身の適法な制作方針、権限、予約 grant、Broker の実行境界に置き換える設計です。

## 各クリエイターの ChatGPT Plan で画像を生成・編集する

ログインした各利用者は、分離された Workspace 内に自分専用の ChatGPT/Codex identity を保持できます。そのユーザーの Plan と Codex 画面で画像 tool が提供される場合、既存 Plan の利用枠を使って `gpt-image-2` から背景コンセプト、環境設定画、キャラクター設定画、絵コンテを**生成・編集**できます。すべての Workspace に共通の OpenAI API key を置いたり、視覚的な試行を毎回別請求の API account へ送ったりする必要はありません。

節約以上に重要なのは account と data の帰属です。各 CLI はスタジオ共通 API identity ではなく、制作者本人の ChatGPT login を使用します。OpenAI が処理する content はそのユーザーの Codex 利用経路に属し、account の規約と data control に従います。local Codex history、project file、credential は各ユーザー専用の persistent Workspace storage に保持されます。企業 Plan identity は、明示的に割り当てる別オプションとして残ります。OpenAI も、[local workflow はユーザーの device で動作し、ChatGPT data control が Codex で処理される content に適用される](https://help.openai.com/en/articles/11369540-using-codex-with-chatgpt)と説明しています。

これは会計上の言い換えではありません。OpenAI は [ChatGPT subscription と API が別会計である](https://help.openai.com/en/articles/9039756)と明記しており、このプロジェクトが subscription を API credit に変換することはありません。各利用者のログイン済み Plan 経路を保ち、その Plan と画面で画像 tool が実際に利用できる場合だけ使用します。API fallback は引き続き API として課金され、各 account はその時点の Plan 上限、規約、不正利用防止条件に従います。

**5 人スタジオの透明な試算（価格確認日 2026-08-28）：**5 人全員が[月額 200 USD の ChatGPT Pro 20x](https://help.openai.com/en/articles/9793128)をすでに契約し、1 人あたり 1 日 100 回の単画像生成または編集を月 20 営業日行うと仮定します。月間出力は `5 × 100 × 20 = 10,000` 枚です。[GPT-Image-2 公式コスト表](https://developers.openai.com/api/docs/guides/image-generation#token-usage-and-costs)では、1536×1024 出力は Medium が 1 枚 0.041 USD、High が 0.165 USD です。

| 同じ月間作業をすべて API に送る場合 | 回避できる出力 API 費用 |
| --- | ---: |
| Medium 横長画像の生成・編集 10,000 回 | **約 410 USD/月**、4,920 USD/年 |
| High 横長画像の生成・編集 10,000 回 | **約 1,650 USD/月**、19,800 USD/年 |

画像編集では入力画像 Token と入力テキスト Token も課金されるため、API を使う場合の実費はこの出力のみの金額より高くなり得ます。この試算では、同じ制作 workflow が消費する会話、Agent 推論、tool call、retrieval、長い project context の API Token も意図的に除外しているため、回避できる API 総額はさらに大きい可能性があります。既存 5 契約の合計は月 1,000 USD ですが、チームがすでに所有しているという前提なので新規コストとして差し引いていません。この導入だけのために Plan を新規契約する場合は、純削減額から subscription 費用を引く必要があります。「20x」は Plan tier であり、固定画像枚数の 20 倍を保証するものではありません。実際の削減額は、既存 Plan の利用枠内で完了した作業量に、その時点の API 単価を掛けた値です。

## 万能インストーラーではなく、方針を持ったリファレンス実装

このプロジェクトは作者の個人的な興味から始まり、実際に所有する特定の AI サーバー群を対象に実装されました。動作する契約を備えた技術リファレンスであり、あらゆるラック、GPU、ハイパーバイザー、ストレージ、Firewall、モデルサーバー、企業 ID 基盤向けに抽象化された商用インストーラーではありません。

そのため文書は、架空の汎用性より明示的で監査可能な参照トポロジーを優先します。systemd unit、Socket path、Compose network、node role、GPU state transition、運用上の前提は開発時の実システムを説明しています。**すべてのサーバー配置に最適化されているわけではなく**、異なる環境へコマンドをそのままコピーしても導入設計にはなりません。

利用する場合は、Codex を移植エンジニアとして使ってください。`AGENTS.md` と導入ガイドを読ませ、実機を inventory したうえで次を対応付けます。

- Portal、Workspace、Broker、Adapter、Worker、モデルサーバーの配置
- GPU、runtime、VRAM transition、storage、persistent volume
- LAN CIDR、DNS、TLS、Firewall、Unix Socket、SSH tunnel、egress
- systemd/Compose の所有権、実行 user、secret、backup、recovery
- モデル alias、API compatibility、context limit、acceptance test

再利用できる本質はアーキテクチャ、セキュリティ境界、AI handoff の方法です。具体的な設定値は例にすぎません。粗い部分や環境固有の作業は残っており、最終的な導入結果は各運用者が自身のインフラ上で検証し責任を持つ必要があります。

## この参照実装を自社向け内部 AI プラットフォームに移植する

可能です。このリポジトリは顧客固有のサーバー構成に合わせて、同等の機能を持つシステムへ移植できます。対象には、AI サーバー予約サイトと管理コンソール、ユーザーごとに分離された Workspace、プロジェクト別メディアライブラリ、共有企業 AI Plan または個人アカウントを使う Codex/Claude Session、ローカル／外部モデルのルーティング、MiniMax H3 制作 workflow が含まれます。Codex や別の開発エージェントは、このリポジトリを実行可能な仕様として利用でき、これらの契約をゼロから再設計する必要はありません。

顧客向け導入では、次の一連の作業を扱えます。

- 実際のサーバー、GPU、VRAM、network、storage、model runtime、identity boundary、運用制約を inventory する
- Portal、Gateway、PostgreSQL、Redis、Manager、Router、Broker、Adapter、model service、1 台以上の Compute Worker を配置・設定する
- ローカルモデル、OpenAI-compatible な外部 endpoint、ComfyUI custom node、MiniMax H3、管理者承認済みの追加 media workflow を接続する
- GPU 予約、node ごとの secret、model manifest、個人アカウント、企業 AI Plan、tmux Session、Skill、AI handoff file を設定する
- DNS、TLS、SMTP、Firewall、egress、backup、recovery、最小権限の service ownership を構成する
- 顧客専用の `AGENTS.md`、`CLAUDE.md`、server context、runbook、acceptance test を納品し、別の Codex／Claude Session が安全に引き継げるようにする

顧客または運用者は、正確なハードウェア／ネットワーク一覧、使用予定のモデルと runtime、domain と identity の要件、チームと予約のルール、storage 要件、承認済みの管理者インストール方法を提供する必要があります。API key や password を **AI の会話へ貼り付ける必要はありません**。対象サーバー上で直接生成し、Git の対象外にした secret file または顧客の secret manager に格納してください。

現在の実装は x86_64 Linux、Docker Compose、systemd、PostgreSQL、Redis、LAN 接続された AI サーバー向けに最適化されています。ARM、Kubernetes、cloud GPU fleet、複数 VLAN、zero-trust overlay、SSO、異なる model server も移植先にできますが、サンプルトポロジーの一括置換ではなく明示的な設計が必要です。実ユーザーが適切な GPU node を予約し、正しく分離された Workspace に入り、選択したローカル／外部モデルを呼び出し、承認済み media job を実行し、検証済み artifact を取得できて初めて導入完了です。Container が Healthy と表示されるだけでは完了ではありません。

## クイックスタート

```bash
git clone https://github.com/linkprint/local-ai-movie-workspace.git movie-ai
cd movie-ai
sh ops/bootstrap.sh
```

Git 管理外の `.env` と `env/laravel.env` を編集し、AppArmor、Host Control、モデル Socket を導入してから起動します。

```bash
docker compose build
docker compose --profile workspace-build build movie-workspace-image
docker compose up -d movie-postgres movie-redis
docker compose run --rm --no-deps movie-web php artisan migrate --force
docker compose run --rm --no-deps movie-web php artisan db:seed --force
docker compose up -d
docker compose exec movie-web php artisan movie:create-admin \
  --name="Initial Administrator" --email="admin@example.com" --timezone="UTC"
```

最後のコマンドを実行する前に実運用の SMTP を設定してください。初期パスワードを平文で作成・送信せず、1 回限りのパスワード設定リンクだけを送信します。

Migration には完全な PostgreSQL schema が含まれます。公開 Seeder はサニタイズ済み
ノード template と会社 Codex lease singleton だけを補い、ユーザー、予約、project、
session、job、audit、media record は作成しません。

詳細は [AI インストール・運用・引き継ぎガイド](docs/AI_INSTALL_AND_OPERATIONS_GUIDE.md) を参照してください。ローカル/外部モデル、外部プロバイダーブリッジ、MiniMax H3、Codex 認証、tmux、skill、バックアップ、テスト、公開手順を説明します。

## Skill のロードを保証

管理 skill は Codex 公式の管理スコープ `/etc/codex/skills` に配置されます。Workspace 起動時に次を実行します。

```bash
movie-ai skills verify
```

必須 `SKILL.md`、名前、説明、読み取り専用権限に問題があれば安全側に失敗します。新しい Workspace では `/skills` で確認し、重要なメディア処理は `$skill-name` を明示します。

## セキュリティ

目標は単に「セルフホストだから安全」と言うことではなく、影響範囲を実際に小さくすることです。制作者には仕事を完了する能力を渡し、各 browser Session をサーバー管理の入口にはしません。

- **最小権限 Workspace。** Container は非特権 user、read-only root filesystem、Linux capability の drop、`no-new-privileges`、tmpfs、seccomp、AppArmor、internal network、controlled egress を使用します。`privileged`、`SYS_ADMIN`、`seccomp=unconfined` はサポート対象外です。
- **Host shell ではなく限定 capability。** Workspace は Docker、SSH、systemd、Host Control Socket、LAN model port、任意 ComfyUI workflow を受け取りません。審査済み host action は狭い Unix Socket contract、media job は制限付き `movie-ai` CLI と Broker schema を通ります。
- **予約は authorization boundary でもある。** 署名付き短期 grant は user、project、compute node、reservation、expiry を固定します。Broker が Job state と revoke を管理し、PostgreSQL exclusion constraint が所有権の重複を防止します。期限切れ、no-show、cancel、abandon で model grant を回収します。
- **Provider key を持たない制作 Session。** Credential は root-readable secret、Secret Manager、または管理者所有 Adapter に残します。モデルは固定 Socket 経由で Broker に入り、Workspace が受け取るのは予約範囲 API だけです。
- **Identity と project の分離。** 個人／企業 AI identity は別 volume を使い、user と project ごとに state と media を永続化します。共同作業のために credential や他人の CLI history をコピーする必要はありません。
- **Topology は control plane の後ろに隠す。** User API は node IP、内部 Broker URL、内部 health detail、HMAC Secret を返しません。Controlled Egress により、侵害された Workspace を network pivot に使う価値も下げます。
- **認証と証跡。** Portal は role と TOTP をサポートし、重要な予約、管理、Runtime 操作を記録します。
- **公開時にも Gate を適用。** Provider Key、秘密鍵、password、`auth.json`、user media、本番 DB record、DB dump は Git に含めません。完全な migration とサニタイズ済み bootstrap data を公開し、tree/history scanner を自動実行します。

これは automated gate を備えた security-oriented reference architecture であり、正式な security certification や独立 penetration test の完了を意味しません。Docker host と administrator は引き続き trust anchor です。各導入先で TLS、firewall/egress、secret rotation、patch、backup、recovery、外部 model provider の data handling を実機検証する必要があります。

## 現在の境界

中央 Portal と単一ノード経路は実装済みです。マルチノード用のスキーマ、ノード登録、ヘルスチェックはコードとして収録済みで、計算ノード追加の手順はインストールガイド §8 にあります。ただし Worker のルーティングと障害 Gate は実機でのマルチノード検証が未完了のため、本番マルチノード対応とは称しません。

## AI と開発

Codex は [AGENTS.md](AGENTS.md) を読み、Claude Code は [CLAUDE.md](CLAUDE.md) から案内されます。どちらも `Portal -> Manager -> Broker -> Adapter` の境界を維持します。

## 検証

```bash
python3 -m unittest discover -s ops/tests -p 'test_*.py'
sh ops/tests/gate4-static.sh
python3 ops/tests/public_release_scan.py --tree
```

Issue と焦点を絞った Pull Request を歓迎します。セキュリティ境界を維持し、テストを追加し、ウェイトや認証情報を送信しないでください。

MIT License。詳細は [LICENSE](LICENSE) を参照してください。
