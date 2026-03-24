# **Automated WordPress Plugin & Theme Auditing** **Tool – Overview**

## **Introduction**



The WordPress plugin ecosystem has grown dramatically. In 2025 alone the Plugins Team reviewed
more than **12 000 plugins** and incorporated automated and AI‑assisted checks to cope with the volume

[1](https://make.wordpress.org/plugins/2026/01/07/a-year-in-the-plugins-team-2025/#:~:text=,no%20reply%20from%20their%20author) . Even with automation, the Plugins Team must verify each submission for subject‑matter

restrictions, licensing, security and code quality [2](https://make.wordpress.org/plugins/handbook/performing-reviews/#:~:text=Overview) . To help handle this volume, you will build a system
that automatically audits plugins and themes using the **WordPress Model Context Protocol (MCP)** and
**OpenAI Codex** .



[1](https://make.wordpress.org/plugins/2026/01/07/a-year-in-the-plugins-team-2025/#:~:text=,no%20reply%20from%20their%20author)



[2](https://make.wordpress.org/plugins/handbook/performing-reviews/#:~:text=Overview)



This tool’s goal is to reproduce (and eventually supplement) the internal scanner used by the Plugins
Team. It will check submitted plugins or themes against the official guidelines—such as the requirement
that all code comply with the GPL [3](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20Guidelines), that developers are responsible for all included files [4](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=2,and%20actions%20of%20their%20plugins), and that
plugins must not track users without consent [5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=7,users%20without%20their%20consent) . For themes, it will verify licensing and copyright
information [6](https://make.wordpress.org/themes/handbook/review/required/#:~:text=1), ensure user‑data collection is opt‑in [7](https://make.wordpress.org/themes/handbook/review/required/#:~:text=2), and check accessibility features like skip‑links

[8](https://make.wordpress.org/themes/handbook/review/required/#:~:text=Skip%20links%20Themes%20must%20include,on%20entering%20any%20given%20page) .



[3](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20Guidelines) [4](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=2,and%20actions%20of%20their%20plugins)



[5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=7,users%20without%20their%20consent)



[6](https://make.wordpress.org/themes/handbook/review/required/#:~:text=1) [7](https://make.wordpress.org/themes/handbook/review/required/#:~:text=2)



[8](https://make.wordpress.org/themes/handbook/review/required/#:~:text=Skip%20links%20Themes%20must%20include,on%20entering%20any%20given%20page)


## **Objectives**



1.


2.


3.


4.



**Automate guideline checks.** The tool will inspect a plugin or theme’s code, metadata and
readme to confirm compliance with WordPress.org guidelines. It will look for licensing
information, human‑readable code [9](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20Guidelines), opt‑in data collection [5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=7,users%20without%20their%20consent), valid uninstallation hooks,
sanitized inputs [10](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Security) and other requirements.
**Use the Model Context Protocol.** WordPress MCP provides a standardized way for AI agents to
interact with WordPress. Tools registered through the MCP can retrieve resources (like plugin
files or posts) and execute callbacks inside WordPress [11](https://github.com/Automattic/wordpress-mcp#:~:text=Adding%20Custom%20Tools) . Your auditing plugin will register a
**custom tool** that receives a plugin or theme slug, collects relevant files and metadata, analyzes
them with OpenAI Codex and returns a structured report.
**Leverage OpenAI Codex for analysis.** Codex can interpret PHP, JavaScript and CSS. The auditing
tool will send file contents to Codex and instruct it to detect guideline violations—such as
unescaped SQL queries, direct calls to `$_POST` without sanitization [10](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Security), or inclusion of



outdated third‑party libraries [12](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=3rd%20Party%20Libraries) . The response will be parsed and summarized for reviewers.
**Generate actionable reports.** The tool should return a human‑readable report highlighting
each issue (e.g., license mismatch, unsanitized input, missing readme section), the file and line
number if available, and suggested fixes. Reports may be stored as a custom post type in
WordPress or exported as a downloadable file.



[9](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20Guidelines) [5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=7,users%20without%20their%20consent)



[10](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Security)



[11](https://github.com/Automattic/wordpress-mcp#:~:text=Adding%20Custom%20Tools)



[10](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Security)


## **Architecture**

**Components**



1.



**WordPress Site with MCP plugin.** Install a local or staging WordPress instance and the
**WordPress MCP** plugin. MCP defines a “model context” containing **tools**, **resources** and


1


**prompts** [13](https://github.com/Automattic/wordpress-mcp/blob/trunk/docs/for-ai.md#:~:text=Summary) . Tools perform actions; resources expose data to the AI; prompts provide







2.


3.


4.


5.



**Custom auditing plugin.** You will write a WordPress plugin (e.g., `auditor-plugin.php` ) that


installation, gather metadata (readme contents, headers, screenshots), and feed them into
OpenAI Codex for analysis.


**Resources for file access.** Use MCP’s `register_resource` API to expose the list of installed

plugins and themes, their files, and the contents of specific files [14](https://github.com/Automattic/wordpress-mcp#:~:text=You%20can%20extend%20the%20MCP,in%20your%20plugin%20or%20theme) . This allows the AI to explore
the code base interactively if needed.


**Codex integration layer.** Inside the auditing plugin you’ll build a client that calls the OpenAI API.
The client will chunk large files, send them with instructive prompts (e.g., “Identify any
unsanitized SQL queries or dangerous functions in the following PHP code”), and collect
responses. Keep the API key in a WordPress option or environment variable, and avoid logging it
or exposing it via REST endpoints. Note that developers are responsible for the contents and
actions of their plugins [4](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=2,and%20actions%20of%20their%20plugins) —including secure handling of API keys.


**Report generator and storage.** After analysis, the plugin will compile the issues into a
structured report. You can register a custom post type (e.g., `mcp_audit_report` ) so that each

audit appears in the WordPress admin with metadata (slug, timestamp, summary). Alternatively,
the report can be returned as a JSON object via the MCP tool response.



**High‑Level Workflow**



1.


2.


3.


4.


5.



**Submission.** A plugin or theme is uploaded to the WordPress instance (for example, via the
review queue). The auditor plugin may automatically detect new uploads via hooks or may run
on demand.
**Tool invocation.** A reviewer or AI agent calls the `audit_plugin_or_theme` MCP tool, passing

the slug and desired checks. MCP validates the input and triggers your callback.
**Data collection.** The callback uses WordPress functions to locate the plugin/theme directory,
reads files, parses the `readme.txt` and metadata, and collects any user‑facing text for privacy

and accessibility checks.
**Analysis via Codex.** For each check, the callback sends code and context to OpenAI Codex.
Codex returns a list of potential issues along with explanations and suggested fixes.
**Aggregation and reporting.** The callback aggregates Codex’s feedback, cross‑references the
WordPress guidelines (e.g., verify GPL license text [3](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20Guidelines), ensure no tracking without consent [5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=7,users%20without%20their%20consent),
confirm skip links exist [8](https://make.wordpress.org/themes/handbook/review/required/#:~:text=Skip%20links%20Themes%20must%20include,on%20entering%20any%20given%20page), etc.), and produces a human‑readable report. The report is saved or
returned to the caller.



[3](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20Guidelines) [5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=7,users%20without%20their%20consent)



[8](https://make.wordpress.org/themes/handbook/review/required/#:~:text=Skip%20links%20Themes%20must%20include,on%20entering%20any%20given%20page)



2


## **Integration with Review Workflow**

The Plugin Directory Reviewers’ Handbook outlines the manual review process: vet the author,
download the plugin, check subject matter, security, code quality, and send a list of issues if necessary

[2](https://make.wordpress.org/plugins/handbook/performing-reviews/#:~:text=Overview) . Your automated tool will support this process by flagging many common issues early:







**Licensing & attribution:** Ensure the plugin or theme is GPL‑compatible and includes copyright
and license details [6](https://make.wordpress.org/themes/handbook/review/required/#:~:text=1) .
**Human‑readable code:** Detect obfuscated code or minified files that hide functionality [15](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=4,human%20readable) .
**Security & sanitization:** Find uses of `$_POST`, `$_GET` or raw SQL queries without sanitization



[6](https://make.wordpress.org/themes/handbook/review/required/#:~:text=1)




- **Human‑readable code:** Detect obfuscated code or minified files that hide functionality [15](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=4,human%20readable)







or prepared statements [10](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Security) .



[10](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Security)







internationalized. For plugins, ensure admin screens are accessible via keyboard.


By combining MCP’s structured environment with Codex’s code understanding, your tool can
significantly reduce the time reviewers spend on repetitive code audits and let them focus on more
nuanced tasks, improving the quality and security of the WordPress ecosystem.

# **Setting Up the Auditing Environment**


This file explains how to create a local or staging environment to develop and run the automated

WordPress plugin/theme auditing tool. The steps below assume familiarity with basic system
administration, PHP and WordPress development.

## **1. Prepare a WordPress installation**



1. **Install a local web stack.** You can use any LAMP/LEMP environment (e.g., Apache/Nginx with

PHP 8.1+ and MySQL/MariaDB). Tools like [Local WP, DevKinsta](https://localwp.com/) or Docker images (e.g.,

`wordpress:latest` ) make this easy. Ensure the PHP version matches the latest WordPress

requirements.

2. **Download and install WordPress.** Obtain the latest stable WordPress release from

[wordpress.org. Unzip it into your web server directory, configure](https://wordpress.org) `wp-config.php` and run the

installation.

3. **Create a dedicated site for auditing.** It’s recommended to isolate auditing from production.

You can install WordPress in a sub‑directory or sub‑domain dedicated to plugin/theme reviews.


## **2. Install the WordPress MCP plugin**

The **WordPress Model Context Protocol** plugin allows AI agents to interact with WordPress via tools
and resources. To install it:



1. **Download the plugin.** The MCP plugin is available on GitHub. Clone or download the repository

into your `wp-content/plugins` directory:


3


```
cd wp-content/plugins
git clone https://github.com/wordpress/mcp.git wordpress-mcp

```


1.


2.



**Activate the plugin.** In the WordPress admin dashboard go to **Plugins → Installed Plugins** and
activate **WordPress MCP** . You can also use WP‑CLI: `wp plugin activate wordpress-mcp` .


**Review the MCP architecture.** MCP exposes hooks to register **tools**, **resources** and **prompts** . A
tool is a function that the AI can execute; a resource provides data to the AI; prompts define
default instructions. Registration happens via `wp_mcp_register_tools` or by instantiating


## **3. Obtain OpenAI credentials and install dependencies**

Your audit plugin will call OpenAI Codex (via the Chat Completion API). To use it:



1.


2.


3.


4.



**Create an API key.** Sign up at [platform.openai.com](https://platform.openai.com/) and generate a secret API key. Keep the key
secure; do not commit it to version control or expose it via public endpoints. Plugin developers
are responsible for the contents and actions of their plugins [4](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=2,and%20actions%20of%20their%20plugins), including secure handling of
API keys.


**Install an HTTP client.** You can call the OpenAI API directly using WordPress’s HTTP functions
( `wp_remote_post` ), but using a client library simplifies the process. Two options:


**PHP library:** Install `openai-php/client` via Composer:

```
 cd wp-content/plugins/auditor-plugin
 composer require openai-php/client

```

This library wraps the OpenAI REST API. Remember to autoload Composer in your plugin. *
**External microservice:** Alternatively, implement the Codex call in a Node.js or Python service
that receives code, queries the OpenAI API, and returns results. Your WordPress plugin can
communicate with the service over HTTP.


**Store the API key securely.** Use the WordPress options API or environment variables. For
example, add a `OPENAI_API_KEY` constant to `wp-config.php` and read it in your plugin.

Never expose it in responses.


## **4. Create the auditor plugin skeleton**



1.




```
<?php
/*
* Plugin Name: MCP Auditor
* Description: Registers an MCP tool to audit plugins and themes for
guideline compliance.

```

4


```
 * Author: Your Name
 * Version: 0.1.0
 */
 defined('ABSPATH') or exit;

 // Use Composer autoload if installed
 if (file_exists(__DIR__ . '/vendor/autoload.php')) {
   require_once __DIR__ . '/vendor/autoload.php';
 }

 // Include class files (you will add these later)
 require_once __DIR__ . '/includes/class-auditor-mcp-tool.php';

```






1.



**Set up plugin structure.** Create sub‑folders like `includes/` for classes, `assets/` for scripts,

and `templates/` if needed. Keep code human‑readable and avoid minification inside the



repository; obfuscated code is prohibited [15](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=4,human%20readable) .

## **5. Register the MCP tool and resources**


Inside `class-auditor-mcp-tool.php` you will register a tool and optional resources. The general

pattern is:

```
 <?php
 class Auditor_MCP_Tool {
   public function __construct() {
      add_action('wp_mcp_register_tools', [$this, 'register_tool']);
      add_action('wp_mcp_register_resources', [$this,
 'register_resources']);
   }

   public function register_tool($registry) {
      $registry->register_tool([
        'name' => 'audit_plugin_or_theme',
        'description' => 'Audit a plugin or theme for guideline
 compliance.',
        'inputSchema' => [
           'type' => 'object',
           'properties' => [
             'slug' => ['type' => 'string', 'description' =>
 'Plugin or theme slug'],
             'type' => ['type' => 'string', 'enum' =>
 ['plugin','theme'], 'description' => 'Resource type'],
             'checks' => ['type' => 'array', 'items' => ['type' =>
 'string'], 'description' => 'Checks to perform'],
           ],
           'required' => ['slug', 'type'],
        ],

```

5


```
       'callback' => [$this, 'audit_callback'],
       'permission_callback' => function() {
          return current_user_can('manage_options');
       },
     ]);
  }

  public function register_resources($registry) {
     // Example: expose list of installed plugins and themes
     $registry->register_resource([
       'name' => 'plugins_list',
       'description' => 'List installed plugins',
       'get_content' => function ($args) {
          return array_keys(get_plugins());
       },
     ]);
  }

  public function audit_callback($args) {
     // Validate input
     $slug = sanitize_key($args['slug']);
     $type = $args['type'];

     // Locate path
     if ($type === 'plugin') {
       $all_plugins = get_plugins();
       $plugin_path = array_key_exists($slug . '/' . $slug . '.php',
$all_plugins)
          ? WP_PLUGIN_DIR . '/' . $slug
          : false;
     } else {
       $theme = wp_get_theme($slug);
       $plugin_path = $theme->exists() ? get_theme_root($slug) . '/' .
$slug : false;
     }

     if (!$plugin_path) {
       return ['error' => 'Invalid slug'];
     }

     // Collect files and metadata (see development.md)
     $report = $this->run_audit($plugin_path, $type, $args['checks'] ??
[]);

     // Save report or return it directly
     return $report;
  }

  private function run_audit($path, $type, $checks) {
     // You will implement actual analysis in development.md
     return ['status' => 'not implemented'];

```

6


```
   }
 }

 // Initialize the tool
 new Auditor_MCP_Tool();

```

This example shows how to register a tool and a simple resource. MCP will automatically expose

`audit_plugin_or_theme` to AI models using the defined schema [11](https://github.com/Automattic/wordpress-mcp#:~:text=Adding%20Custom%20Tools) .

## **6. Security considerations**


WordPress security guidelines stress that developers should **never trust user input** and must sanitize
and escape data [17](https://developer.wordpress.org/apis/security/#:~:text=When%20developing%2C%20it%20is%20important,progress%20through%20your%20development%20efforts) . When processing slugs or file contents in your callback:


   - Use `sanitize_key()` or `sanitize_text_field()` to clean user input.

   - Validate the plugin/theme slug exists before reading files.



When making HTTP requests to external services (OpenAI), use HTTPS and handle errors
gracefully.








## **7. Testing the setup**



1. Activate your `MCP Auditor` plugin in the WordPress dashboard.

2. Navigate to the MCP UI (provided by the WordPress MCP plugin) and verify that your

`audit_plugin_or_theme` tool appears with the correct input schema. Alternatively, call it

programmatically by sending a JSON payload to the MCP REST endpoint.

3. Run a test audit on a simple plugin (e.g., Hello Dolly) to ensure your callback locates the plugin

files and returns a placeholder report. You will implement real checks later.



By completing these steps you establish a secure and extensible environment for developing the
automated auditing tool. Proceed to the development guide to implement the actual auditing logic and
integrate OpenAI Codex.

# **Developing the MCP Auditor Tool**


This guide provides detailed instructions for implementing the auditing logic inside your custom
WordPress plugin. The goal is to perform automated checks on plugins or themes, interpret the results
with OpenAI Codex and generate a report that reviewers can use.

## **1. Loading plugin/theme data**


The `audit_callback` function in `Auditor_MCP_Tool` locates the plugin or theme directory. To

prepare data for analysis:



1. **Enumerate files.** Use `RecursiveIteratorIterator` and `RecursiveDirectoryIterator`

to traverse all files in the target directory, skipping dot files. Consider limiting analysis to source
files ( `.php`, `.js`, `.css` ), language files ( `.po`, `.mo` ), templates and the `readme.txt` .


7


```
private function collect_files(string $base_path): array {
$files = [];
$iterator = new \RecursiveIteratorIterator(new
\RecursiveDirectoryIterator($base_path));
foreach ($iterator as $file) {
if ($file->isFile()) {
$ext = strtolower($file->getExtension());
if (in_array($ext, ['php','js','css','twig','html','md','txt']))
{
$files[] = $file->getPathname();
}
}
}
return $files;
}

```


1.


2.







and that the text domain matches the slug.





and that the text domain is present.

## **2. Performing basic heuristic checks in PHP**


Before invoking Codex, some issues can be detected with simple heuristics:



[6](https://make.wordpress.org/themes/handbook/review/required/#:~:text=1)



1.


2.



**Check file paths.** Plugins must not save files to their own directory because it is removed during



**Validate uninstall script.** If an `uninstall.php` file exists, ensure it starts with:




```
 if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
 exit();
 }

```

as required by the review walkthrough [20](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Uninstall%20and%20Deactivation) . Also verify that uninstallation removes custom database
tables and options without deleting user content unexpectedly.


and report them.


8


2.


3.



**Detect minified or obfuscated code.** Lines longer than a certain threshold (e.g., 300 characters)
or containing an unusually high ratio of non‑whitespace characters can indicate minified JS/CSS
or obfuscated PHP. Obfuscation is prohibited [15](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=4,human%20readable) ; minified assets are acceptable if the original
source is included. Flag such files and verify that the source is present.


**Identify remote includes.** Search for references to external hosts in the code (e.g.,



service contexts [22](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=The%20one%20exception%20to%20this,of%20what%20are%20not%20permitted) . For themes, ensure assets like fonts are loaded from allowed CDNs.





[22](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=The%20one%20exception%20to%20this,of%20what%20are%20not%20permitted)



These heuristic checks provide quick feedback and reduce the load on Codex. Include them in your

`run_audit` method, building an array of issues with severity and recommendations.

## **3. Invoking OpenAI Codex for deeper analysis**


Once you have collected files and performed heuristics, you can use Codex to identify subtler issues.
Codex excels at pattern recognition in code, such as detecting unsanitized inputs, SQL injections,
cross‑site scripting (XSS) vulnerabilities, insecure redirects or usage of outdated APIs.


**3.1 Constructing prompts**


Prepare a clear prompt that instructs Codex to audit code according to WordPress guidelines. For
example:

```
 $system_prompt = "You are a WordPress security auditor. You evaluate PHP and
 JavaScript code for compliance with the WordPress Plugin and Theme
 Guidelines.\n"
 . "When given a code snippet, list any issues such as: missing or incorrect
 GPL license, use of obfuscated code, calls to \$_GET or \$_POST without
 sanitization, direct SQL queries without using $wpdb->prepare(), missing
 nonces on form submissions, inclusion of remote scripts, or use of
 unmaintained libraries. For each issue, explain why it is a problem and
 suggest a fix.";

 // When analysing many files, chunk them into reasonable lengths (e.g. 3000
 tokens)
 $message = [
 ['role' => 'system', 'content' => $system_prompt],
 ['role' => 'user', 'content' => "Audit the following code snippet:\n\n" .
 $code_chunk],
 ];

```

Break large files into smaller chunks to stay within token limits and call Codex separately for each. To
improve performance, skip files that are obviously harmless (e.g., translation files or images).


**3.2 Making the API call**


If you installed the `openai-php/client` library, you can call the API like this:


9


```
 use OpenAI\Client;

 $client = OpenAI::factory()->withApiKey(getenv('OPENAI_API_KEY'))->make();

 $response = $client->chat()->create([
 'model' => 'gpt-4',
 'messages' => $message,
 'temperature' => 0.0,
 ]);

 $content = $response->choices[0]->message->content;

```

Alternatively, use WordPress’s HTTP functions:

```
 $body = json_encode([
 'model' => 'gpt-4',
 'messages' => $message,
 'temperature' => 0.0,
 ]);

 $args = [
 'headers' => [
 'Content-Type' => 'application/json',
 'Authorization' => 'Bearer ' . getenv('OPENAI_API_KEY'),
 ],
 'body'  => $body,
 'timeout' => 30,
 ];

 $response = wp_remote_post('https://api.openai.com/v1/chat/completions',
 $args);

 if (!is_wp_error($response)) {
 $data  = json_decode(wp_remote_retrieve_body($response), true);
 $content = $data['choices'][0]['message']['content'] ?? '';
 }

```

Parse the response and extract each issue. It’s helpful to ask Codex to output JSON for easier parsing:

```
 // In the system prompt add:
 "Respond using a JSON array where each element has 'type', 'file', 'line',
 'issue', and 'recommendation'."

```

Then decode the JSON using `json_decode()` .


10


**3.3 Rate limiting and caching**


OpenAI APIs have rate limits. Cache results (e.g., using transients) to avoid redundant calls. Respect
user privacy—never send confidential data to the API without consent [5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=7,users%20without%20their%20consent) .

## **4. Generating reports**


After collecting issues from heuristics and Codex:



1. **Aggregate findings.** Combine issues by severity and category (licensing, security, privacy,

accessibility, naming, deactivation). Remove duplicates.

2. **Structure the report.** Use an associative array with keys like `summary`, `issues`,

`recommendations`, `timestamp`, `plugin_slug`, `type` . Example:


```
$report = [
'summary' => sprintf('Audit completed with %d issues found.',
count($issues)),
'issues' => $issues,
'timestamp' => current_time('mysql'),
'plugin_slug' => $slug,
'type' => $type,
];

```


agent or reviewer can display it.



[23](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Sanitization%2C%20Validation%2C%20Escaping)



.


## **5. Extending checks**

The initial implementation can focus on core guidelines. Over time you can add modules for:







**Plugin subject matter restrictions:** Reject plugins duplicating core features or promoting
unethical purposes (spam, hacking) as noted in the review walkthrough [24](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=In%20general%20this%20is%20explained,be%20accepted%20are%20as%20follows) .
**Block theme specifics:** Check `theme.json` for proper settings, ensure block templates use



[24](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=In%20general%20this%20is%20explained,be%20accepted%20are%20as%20follows)







valid markup, etc.

- **Internationalization:** Verify all user‑facing strings are wrapped in `__()` or `_e()` and that a

proper `textdomain` is declared.




- **Accessibility compliance:** For themes, search for skip links [8](https://make.wordpress.org/themes/handbook/review/required/#:~:text=Skip%20links%20Themes%20must%20include,on%20entering%20any%20given%20page)



**Accessibility compliance:** For themes, search for skip links [8](https://make.wordpress.org/themes/handbook/review/required/#:~:text=Skip%20links%20Themes%20must%20include,on%20entering%20any%20given%20page), ARIA attributes and contrast
ratios. Codex can help evaluate HTML for accessibility issues.
**Versioning and changelog:** Ensure version numbers increment on every release and that the
readme includes a clear changelog. [25](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20only%20version%20of%20the,directory%2C%20not%20the%20development%20environment)







[25](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20only%20version%20of%20the,directory%2C%20not%20the%20development%20environment)



11


## **6. Putting it all together**

Integrate the heuristic checks and Codex analysis inside your `run_audit` method. Here is a simplified

skeleton:

```
 private function run_audit($path, $type, $checks) {
 $issues = [];

 // Collect files
 $files = $this->collect_files($path);

 // Basic heuristics
 if (in_array('licensing', $checks)) {
 $issues = array_merge($issues, $this->check_license($path));
 }
 if (in_array('security', $checks)) {
 $issues = array_merge($issues, $this >check_security_patterns($files));
 }

 // Codex analysis
 foreach ($files as $file) {
 $code = file_get_contents($file);
 if (!$this->should_send_to_ai($file, $code)) {
 continue;
 }
 $codex_issues = $this->analyze_with_codex($file, $code);
 $issues    = array_merge($issues, $codex_issues);
 }

 // Build report
 return $this->build_report($issues, basename($path), $type);
 }

```

sanitize inputs and escape outputs. Use WordPress APIs wherever possible to avoid reinventing the
wheel and adhere to security best practices [17](https://developer.wordpress.org/apis/security/#:~:text=When%20developing%2C%20it%20is%20important,progress%20through%20your%20development%20efforts) .


By following this development guide, you will implement a robust auditing tool that leverages both
deterministic checks and AI‑assisted analysis to help the Plugins Team efficiently maintain the quality
and safety of the WordPress ecosystem.

# **Audit Checklist for WordPress Plugins & Themes**


Use this checklist as a quick reference when reviewing the report generated by the auditing tool. Each
row lists a guideline category, the high‑level checks performed and the relevant documentation. The


12


auditing tool automates as many of these checks as possible, but manual review may still be required
for ambiguous cases.

























































13










**Note:** This checklist summarizes key points; always refer to the official Plugin and Theme Guidelines for
full details. When the auditing tool flags an issue, cross‑check with the guideline text to confirm the
severity and recommended resolution.



[1](https://make.wordpress.org/plugins/2026/01/07/a-year-in-the-plugins-team-2025/#:~:text=,no%20reply%20from%20their%20author)



A Year in the Plugins Team – 2025 – Make WordPress Plugins



[https://make.wordpress.org/plugins/2026/01/07/a-year-in-the-plugins-team-2025/](https://make.wordpress.org/plugins/2026/01/07/a-year-in-the-plugins-team-2025/)



[2](https://make.wordpress.org/plugins/handbook/performing-reviews/#:~:text=Overview)



Performing Reviews – Make WordPress Plugins



[https://make.wordpress.org/plugins/handbook/performing-reviews/](https://make.wordpress.org/plugins/handbook/performing-reviews/)


[3](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20Guidelines) [4](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=2,and%20actions%20of%20their%20plugins) [5](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=7,users%20without%20their%20consent) [9](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20Guidelines) [15](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=4,human%20readable) [25](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=The%20only%20version%20of%20the,directory%2C%20not%20the%20development%20environment) [26](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=3,its%20WordPress%20Plugin%20Directory%20page) [27](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=5) [28](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=6,is%20permitted) [29](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=8,party%20systems) [30](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/#:~:text=9,illegal%2C%20dishonest%2C%20or%20morally%20offensive) Detailed Plugin Guidelines – Plugin Handbook |

Developer.WordPress.org

[https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/)



[6](https://make.wordpress.org/themes/handbook/review/required/#:~:text=1) [7](https://make.wordpress.org/themes/handbook/review/required/#:~:text=2) [8](https://make.wordpress.org/themes/handbook/review/required/#:~:text=Skip%20links%20Themes%20must%20include,on%20entering%20any%20given%20page)



Required – Make WordPress Themes



[https://make.wordpress.org/themes/handbook/review/required/](https://make.wordpress.org/themes/handbook/review/required/)



[10](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Security) [12](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=3rd%20Party%20Libraries) [16](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Function%2FClass%2FDefine%20Names) [18](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Nonces) [19](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Saving%20Files) [20](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Uninstall%20and%20Deactivation) [21](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Javascript) [22](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=The%20one%20exception%20to%20this,of%20what%20are%20not%20permitted) [23](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=Sanitization%2C%20Validation%2C%20Escaping) [24](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/#:~:text=In%20general%20this%20is%20explained,be%20accepted%20are%20as%20follows)



Review Walkthrough – Make WordPress Plugins



[https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/](https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/)


[11](https://github.com/Automattic/wordpress-mcp#:~:text=Adding%20Custom%20Tools) [14](https://github.com/Automattic/wordpress-mcp#:~:text=You%20can%20extend%20the%20MCP,in%20your%20plugin%20or%20theme) GitHub - Automattic/wordpress-mcp: WordPress MCP — This repository will be deprecated as

stable releases of mcp-adapter become available. Please use https://github.com/WordPress/mcpadapter for ongoing development and support. · GitHub

[https://github.com/Automattic/wordpress-mcp](https://github.com/Automattic/wordpress-mcp)


14


[13](https://github.com/Automattic/wordpress-mcp/blob/trunk/docs/for-ai.md#:~:text=Summary)



wordpress-mcp/docs/for-ai.md at trunk · Automattic/wordpress-mcp · GitHub



[https://github.com/Automattic/wordpress-mcp/blob/trunk/docs/for-ai.md](https://github.com/Automattic/wordpress-mcp/blob/trunk/docs/for-ai.md)



[17](https://developer.wordpress.org/apis/security/#:~:text=When%20developing%2C%20it%20is%20important,progress%20through%20your%20development%20efforts)



Security – Common APIs Handbook | Developer.WordPress.org



[https://developer.wordpress.org/apis/security/](https://developer.wordpress.org/apis/security/)



15


