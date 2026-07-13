#!/usr/bin/env python3
"""
Distribb SEO Writer (Local Reference Implementation)
=====================================================
This script is a REFERENCE/EXAMPLE for Agentic Mode users. It demonstrates
how to generate SEO-optimized articles locally using your own AI and submit
them to Distribb for publishing, analytics, and calendar display.

You are free to modify this script, swap out components (web search, scraping,
AI provider), or rewrite it entirely. The only requirement is that finished
articles are submitted via the Distribb API.

Install: pip install openai requests python-dotenv

Environment variables:
  DISTRIBB_API_KEY  - Your API key (from Distribb Settings)
  DISTRIBB_API_URL  - API base (default: https://distribb.io)
  OPENAI_API_KEY    - Or any OpenAI-compatible provider key
  AI_BASE_URL       - Custom AI base URL (e.g. https://api.groq.com/openai/v1)
  AI_MODEL          - Model name (default: gpt-4.1-mini)
"""

import os
import re
import json
import time
import logging
import argparse
import requests
from datetime import datetime
from dotenv import load_dotenv

load_dotenv()

logging.basicConfig(level=logging.INFO, format='%(asctime)s [%(levelname)s] %(message)s')
logger = logging.getLogger('distribb_writer')

# ── Configuration ──

DISTRIBB_API_KEY = os.getenv('DISTRIBB_API_KEY', '')
DISTRIBB_API_URL = os.getenv('DISTRIBB_API_URL', 'https://distribb.io').rstrip('/')
AI_MODEL = os.getenv('AI_MODEL', 'gpt-4.1-mini')
AI_BASE_URL = os.getenv('AI_BASE_URL')
OPENAI_API_KEY = os.getenv('OPENAI_API_KEY', '')

# Hardcoded test parameters (set these to test without CLI args)
TEST_KEYWORD = "best project management tools for startups"
TEST_PROJECT_ID = None
TEST_ARTICLE_STYLE = "professional"
TEST_LANGUAGE = None  # None -> use the project's configured language (e.g. Greek)

# ── AI Client (swap this for your preferred provider) ──

def get_ai_client():
    from openai import OpenAI
    kwargs = {'api_key': OPENAI_API_KEY}
    if AI_BASE_URL:
        kwargs['base_url'] = AI_BASE_URL
    return OpenAI(**kwargs)


def ai_chat(system_prompt: str, user_prompt: str, temperature: float = 0.3,
            max_tokens: int = 4000, json_mode: bool = False) -> str:
    client = get_ai_client()
    kwargs = {
        'model': AI_MODEL,
        'messages': [
            {'role': 'system', 'content': system_prompt},
            {'role': 'user', 'content': user_prompt}
        ],
        'temperature': temperature,
        'max_tokens': max_tokens,
    }
    if json_mode:
        kwargs['response_format'] = {'type': 'json_object'}

    for attempt in range(3):
        try:
            response = client.chat.completions.create(**kwargs)
            return response.choices[0].message.content.strip()
        except Exception as e:
            logger.warning(f"AI call attempt {attempt+1} failed: {e}")
            if attempt < 2:
                time.sleep(2 ** attempt)
            else:
                raise
    return ""


def parse_json_from_ai(text: str) -> dict:
    if not text:
        return {}
    text = text.strip()
    if text.startswith('```'):
        text = re.sub(r'^```(?:json)?\s*', '', text)
        text = re.sub(r'\s*```$', '', text)
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        match = re.search(r'\{[\s\S]*\}', text)
        if match:
            try:
                return json.loads(match.group())
            except json.JSONDecodeError:
                pass
        match = re.search(r'\[[\s\S]*\]', text)
        if match:
            try:
                return json.loads(match.group())
            except json.JSONDecodeError:
                pass
    logger.warning(f"Failed to parse JSON from AI: {text[:200]}...")
    return {}


# ══════════════════════════════════════════════════════════════
#  DISTRIBB API CLIENT
#  These functions are the interface to Distribb's server.
#  They handle data that MUST come from Distribb:
#    - Internal links (your published articles for cross-linking)
#    - Backlink targets (network exchange URLs)
#    - Business context (competitors, brand voice, AI instructions)
#    - Article submission (saving content to the Distribb calendar)
# ══════════════════════════════════════════════════════════════

def distribb_api(method: str, path: str, params=None, json_data=None) -> dict:
    url = f"{DISTRIBB_API_URL}{path}"
    headers = {
        'Authorization': f'Bearer {DISTRIBB_API_KEY}',
        'Content-Type': 'application/json'
    }
    try:
        if method.upper() == 'GET':
            resp = requests.get(url, headers=headers, params=params, timeout=30)
        else:
            resp = requests.post(url, headers=headers, json=json_data, timeout=60)

        if resp.status_code == 401:
            logger.error("Authentication failed. Check your DISTRIBB_API_KEY.")
            return {'error': 'Invalid API key'}
        if resp.status_code >= 400:
            logger.error(f"API error {resp.status_code}: {resp.text[:500]}")
            return {'error': f'HTTP {resp.status_code}', 'detail': resp.text[:500]}

        return resp.json()
    except requests.exceptions.ConnectionError:
        logger.error(f"Cannot connect to {DISTRIBB_API_URL}. Check DISTRIBB_API_URL.")
        return {'error': 'Connection failed'}
    except Exception as e:
        logger.error(f"API request failed: {e}")
        return {'error': str(e)}


def get_projects() -> dict:
    """List your active projects."""
    return distribb_api('GET', '/api/v1/projects')


def get_business_context(project_id: int) -> dict:
    """Get business name, competitors, AI instructions, language, target audience."""
    logger.info(f"Fetching business context for project {project_id}...")
    return distribb_api('GET', '/api/v1/business-context', params={'project_id': project_id})


def get_internal_links(project_id: int, keyword: str) -> dict:
    """Get published article URLs for internal cross-linking."""
    logger.info(f"Fetching internal link candidates for '{keyword}'...")
    return distribb_api('GET', '/api/v1/internal-links',
                        params={'project_id': project_id, 'keyword': keyword})


def get_backlink_targets(project_id: int, keyword: str) -> dict:
    """Get backlink exchange URLs from the Distribb network."""
    logger.info(f"Fetching backlink exchange targets for '{keyword}'...")
    return distribb_api('GET', '/api/v1/backlink-targets',
                        params={'project_id': project_id, 'keyword': keyword})


def submit_article(project_id: int, keyword: str, title: str, content: str,
                    meta_description: str, scheduled_date: str = None) -> dict:
    """Submit a finished article to Distribb. This is the CRITICAL call --
    it saves the article to your calendar and makes it available for
    CMS publishing, social repurposing, and analytics."""
    logger.info(f"Submitting article to Distribb: '{title}'...")
    return distribb_api('POST', '/api/v1/articles', json_data={
        'project_id': project_id,
        'keyword': keyword,
        'title': title,
        'content': content,
        'meta_description': meta_description,
        'scheduled_date': scheduled_date,
        'status': 'Planned' if scheduled_date else 'Draft',
    })


def publish_article(article_id: int) -> dict:
    """Trigger CMS publishing for an article (WordPress, Webflow, etc.)."""
    logger.info(f"Publishing article {article_id}...")
    return distribb_api('POST', f'/api/v1/articles/{article_id}/publish')


# ══════════════════════════════════════════════════════════════
#  SEO WRITING PRINCIPLES
#  These are the rules that make Distribb articles rank well.
#  Incorporate these into your writing process however you prefer.
# ══════════════════════════════════════════════════════════════

# Universal rules — apply in EVERY language. The AI-detection tells that follow
# are language-specific and get appended based on the article's language.
SEO_WRITING_RULES = """
WRITING QUALITY:
- Write like a knowledgeable human, not AI. Vary sentence length naturally.
- Start sections with a strong statement, question, or data point -- never a generic filler opener.
- Use specific examples, data points, and actionable advice.
- Never bold the primary keyword in text.

INTERNAL LINKING RULES:
- Place links naturally in the middle of paragraphs, never in intros or conclusions.
- Use descriptive anchor text -- never generic "click here" / "read more" style phrasing in ANY language.
- Never place two links in the same paragraph or consecutive paragraphs.
- Format: <a href="EXACT_URL_FROM_LIST">descriptive anchor text</a>

BACKLINK EXCHANGE RULES:
- Weave network backlinks as natural references to relevant resources.
- Do NOT fabricate information about linked sites.
- Use topically relevant anchor text.

COMPETITOR PROTECTION:
- Never link to or positively recommend direct competitors.
- You may mention competitors factually in comparisons but without outbound links.

CONTENT STRUCTURE:
- Use H2 for section headings, H3 for subsections.
- Output valid HTML (not markdown).
- Target 2500-3500 words total across 5-8 sections.
- Include a meta description (155 chars max) with the primary keyword.
"""

# English AI-detection tells — used when the article language is English.
ENGLISH_AI_TELLS = """
BANNED WORDS/PHRASES (English AI-detection triggers):
- "crucial", "comprehensive", "robust", "leverage", "streamline", "delve"
- "It's worth noting", "In conclusion", "In the ever-evolving landscape"
- "Game-changer", "Unlock the power", "Take your X to the next level"
- Do NOT open sections with "In today's..." or "When it comes to...".
"""

# Greek writing guidance — used when the project/article language is Greek.
# Translating English guardrails word-for-word does not work: Greek has its own
# LLM clichés and its own "click here" anchors, so it needs native-language rules.
GREEK_WRITING_RULES = """
WRITING IN GREEK (Ελληνικά):
- Write the ENTIRE article in natural, native Modern Greek (δημοτική) with correct
  monotonic accents (τόνοι). It must read as originally written in Greek, NOT translated.
- Keep untranslated only the terms Greek audiences genuinely use as-is (brand and product
  names, and words like "SEO", "e-shop", "email"). Do not dump English words otherwise.
- Pick one register and keep it consistent: default to the polite plural
  (πληθυντικός ευγενείας) unless the business voice says otherwise.
- Localize for the Greek market: € for prices, Greek examples and references, and phrase
  things the way Greeks actually search -- do not translate the keyword word-for-word if a
  Greek reader would phrase it differently; write naturally around it.

GREEK AI-DETECTION TELLS TO AVOID (do not use these clichés):
- "στη σημερινή εποχή" / "στον σημερινό κόσμο" (the Greek "in today's world")
- "όσον αφορά" as a section opener
- "αξίζει να σημειωθεί" ("it's worth noting")
- "συμπερασματικά" / "εν κατακλείδι" ("in conclusion")
- "στο συνεχώς εξελισσόμενο τοπίο" ("in the ever-evolving landscape")
- "καθοριστικής σημασίας" ("crucial"), "ολοκληρωμένος/η" as filler ("comprehensive")
- "αξιοποιήστε" over-used ("leverage"), "ας εμβαθύνουμε" ("delve")
- "αλλάζει τα δεδομένα" ("game-changer"), "ξεκλειδώστε τη δύναμη" ("unlock the power")
- "ανεβάστε το X στο επόμενο επίπεδο" ("take X to the next level")

GREEK ANCHOR TEXT (never use as link text):
- "κάντε κλικ εδώ", "δείτε εδώ", "διαβάστε περισσότερα", "δείτε περισσότερα",
  "εδώ", "το blog μας" -- use descriptive Greek anchor text instead.

GREEK META DESCRIPTION:
- Write it in Greek, include the primary keyword, keep it <=155 characters.
"""


def is_greek(language: str) -> bool:
    """True if the language string denotes Greek (el / gr / Greek / Ελληνικά / Hellenic)."""
    if not language:
        return False
    l = language.strip().lower()
    return (l in ('el', 'gr') or l.startswith('el-') or l.startswith('el_')
            or l.startswith('gr-') or l.startswith('gr_')
            or 'greek' in l or 'hellenic' in l or 'ελλ' in l)


def language_directive(language: str) -> str:
    """A strong, explicit instruction to produce the whole article in the target language."""
    name = "Greek (Ελληνικά)" if is_greek(language) else (language or "English")
    return (f"WRITE THE ENTIRE ARTICLE IN {name.upper()} -- title, all headings, body text, and "
            f"meta description. Do not switch to another language mid-article. Keep only brand "
            f"names and widely-used technical terms in their original form.")


def build_writing_rules(language: str) -> str:
    """Universal SEO rules composed with the language-specific AI-detection tells."""
    tells = GREEK_WRITING_RULES if is_greek(language) else ENGLISH_AI_TELLS
    return SEO_WRITING_RULES + "\n" + tells


# ══════════════════════════════════════════════════════════════
#  EXAMPLE PIPELINE
#  This is ONE way to generate an article. Feel free to replace
#  the web search, scraping, research, or section writing with
#  your own tools and methods.
# ══════════════════════════════════════════════════════════════

def generate_article(keyword: str, project_id: int, article_style: str = "professional",
                      language: str = None, scheduled_date: str = None,
                      auto_publish: bool = False) -> dict:
    """Example article generation pipeline. Modify as needed.

    ``language`` is optional. If not given, the project's configured language
    (from Distribb business context, e.g. "Greek" / "el") is used, so a Greek
    project produces Greek articles automatically.
    """
    logger.info(f"{'='*60}")
    logger.info(f"Generating article: '{keyword}' (project {project_id})")
    logger.info(f"{'='*60}")

    if not DISTRIBB_API_KEY:
        logger.error("DISTRIBB_API_KEY not set. Get your key from Distribb Settings.")
        return {'error': 'DISTRIBB_API_KEY not set'}
    if not OPENAI_API_KEY:
        logger.error("OPENAI_API_KEY not set.")
        return {'error': 'OPENAI_API_KEY not set'}

    # ── Step 1: Get server-side data from Distribb ──
    biz = get_business_context(project_id)
    if biz.get('error'):
        return biz
    logger.info(f"Business: {biz.get('business_name', '?')} ({biz.get('website_url', '?')})")

    # Resolve the effective language: explicit arg wins, else the project's
    # configured language from business context, else English.
    if not language:
        language = biz.get('language') or 'en'
    logger.info(f"Language: {language}{' (Greek)' if is_greek(language) else ''}")

    links_data = get_internal_links(project_id, keyword)
    internal_links = links_data.get('links', [])
    num_internal = links_data.get('num_links_recommended', 5)
    logger.info(f"Internal links: {len(internal_links)} candidates, recommended: {num_internal}")

    bl_data = get_backlink_targets(project_id, keyword)
    backlink_targets = bl_data.get('targets', [])
    logger.info(f"Backlink targets: {len(backlink_targets)}, credits: {bl_data.get('credits', 0)}")

    # ── Step 2: Build the article context for the AI ──
    competitor_list = ', '.join(biz.get('competitors', [])[:5])

    internal_links_text = ""
    if internal_links:
        internal_links_text = "AVAILABLE INTERNAL LINKS (use " + str(num_internal) + " of these):\n"
        internal_links_text += "\n".join(f"  - {l['url']} | {l['title']}" for l in internal_links[:15])

    backlinks_text = ""
    if backlink_targets:
        backlinks_text = "BACKLINK EXCHANGE URLS (include 1-2 naturally):\n"
        backlinks_text += "\n".join(f"  - {t['url']} | {t.get('title', '')}" for t in backlink_targets[:5])

    competitor_text = ""
    if competitor_list:
        competitor_text = f"COMPETITORS (never link to these): {competitor_list}"

    # ── Step 3: Generate the article in one shot (or adapt to multi-section) ──
    writing_rules = build_writing_rules(language)

    prompt = f"""Write a thorough, SEO-optimized article about: "{keyword}"

TODAY'S DATE: {datetime.now().strftime('%B %d, %Y')}
STYLE: {article_style}
LANGUAGE: {language}

{language_directive(language)}

BUSINESS: {biz.get('business_name', '')} - {biz.get('description', '')[:400]}
TARGET AUDIENCE: {biz.get('target_audience', '')}

{writing_rules}

{internal_links_text}

{backlinks_text}

{competitor_text}

{biz.get('ai_instructions', '')}

OUTPUT FORMAT (JSON):
{{
  "title": "SEO title with primary keyword",
  "meta_description": "155 chars max, includes keyword",
  "content": "<h2>First Section</h2>... full article HTML ...",
  "word_count": 3000
}}

Write 5-8 sections (H2 headings), 2500-3500 words total. Include internal links
and backlinks naturally. Output valid HTML, not markdown."""

    logger.info("Generating article content with AI...")
    writer_role = "You are an expert SEO content writer."
    if is_greek(language):
        writer_role = ("You are an expert SEO content writer and a native Greek speaker who "
                       "writes fluent, natural Modern Greek.")
    raw = ai_chat(
        writer_role + " Return only the requested JSON.",
        prompt, temperature=0.4, max_tokens=12000, json_mode=True
    )
    result = parse_json_from_ai(raw)

    if not result.get('content'):
        logger.error("AI did not return article content.")
        return {'error': 'Article generation failed'}

    title = result.get('title', keyword.title())
    content = result['content']
    meta_desc = result.get('meta_description', '')
    word_count = len(content.split())

    logger.info(f"Article generated: '{title}' ({word_count} words)")

    # ── Step 4: Submit to Distribb (REQUIRED) ──
    submission = submit_article(
        project_id=project_id, keyword=keyword, title=title,
        content=content, meta_description=meta_desc,
        scheduled_date=scheduled_date
    )

    if submission.get('error'):
        logger.error(f"Submission failed: {submission['error']}")
        return submission

    article_id = submission.get('article_id')
    logger.info(f"Article submitted: ID={article_id}, status={submission.get('status')}")

    # ── Step 5: Auto-publish if requested ──
    if auto_publish and article_id:
        pub_result = publish_article(article_id)
        logger.info(f"Publish result: {pub_result}")
        submission['publish_result'] = pub_result

    logger.info(f"{'='*60}")
    logger.info(f"DONE: '{title}' ({word_count} words) -> article ID {article_id}")
    logger.info(f"{'='*60}")

    return {
        'article_id': article_id,
        'title': title,
        'word_count': word_count,
        'keyword': keyword,
        'status': submission.get('status', 'Draft')
    }


# ── CLI ──

if __name__ == '__main__':
    parser = argparse.ArgumentParser(description='Distribb SEO Writer - Reference Implementation')
    parser.add_argument('--keyword', type=str, default=TEST_KEYWORD, help='Target keyword')
    parser.add_argument('--project-id', type=int, default=TEST_PROJECT_ID, help='Distribb project ID')
    parser.add_argument('--style', type=str, default=TEST_ARTICLE_STYLE,
                        choices=['professional', 'casual', 'technical', 'listicle', 'how-to'],
                        help='Article writing style')
    parser.add_argument('--language', type=str, default=TEST_LANGUAGE,
                        help='Content language (e.g. en, el/Greek). Omit to use the project\'s configured language.')
    parser.add_argument('--schedule', type=str, default=None,
                        help='Schedule date (ISO 8601, e.g. 2026-03-25T09:00:00Z)')
    parser.add_argument('--publish', action='store_true', help='Auto-publish after generation')

    args = parser.parse_args()

    if not args.project_id:
        projects = get_projects()
        if projects.get('projects'):
            print("\nAvailable projects:")
            for p in projects['projects']:
                print(f"  ID: {p['ID']} - {p.get('BusinessName', 'Unnamed')} ({p.get('WebsiteUrl', '')})")
            print(f"\nRun with: --project-id <ID>\n")
        else:
            print("No projects found. Set up a project in Distribb first.")
        exit(1)

    result = generate_article(
        keyword=args.keyword,
        project_id=args.project_id,
        article_style=args.style,
        language=args.language,
        scheduled_date=args.schedule,
        auto_publish=args.publish,
    )

    print(json.dumps(result, indent=2, default=str))
