import json
import os
import time
import argparse
from groq import Groq

# ---------------- CONFIGURATION ----------------
# Use environment variable for security.
# Run in terminal: $env:GROQ_API_KEY="your_key"; python universal_translator.py
GROQ_API_KEY = os.environ.get("GROQ_API_KEY")

# Default settings (can be overridden)
BATCH_SIZE = 15
MAX_RETRIES = 3
MODEL = "llama-3.3-70b-versatile"

# Fields to translate
TRANSLATE_FIELDS = [
    "name",
    "description",
    "description_short",
    "meta_title",
    "meta_description",
    "content",
    "legend",
    "title",
    "value",
    "public_name",
    "head_seo_title",
]
# -----------------------------------------------


def get_client():
    if not GROQ_API_KEY:
        print("❌ Error: GROQ_API_KEY not found.")
        print("Please set it in your terminal:")
        print('   On Windows (PowerShell): $env:GROQ_API_KEY="your_gsk_key_here"')
        exit(1)
    return Groq(api_key=GROQ_API_KEY)


def translate_batch(client, batch_dict, target_lang_name="English"):
    """
    Translates a dictionary of {id: text} in one go using JSON mode.
    """
    if not batch_dict:
        return {}

    prompt_items = json.dumps(batch_dict, ensure_ascii=False, indent=2)

    system_prompt = (
        f"You are a professional technical translator for an e-commerce store (Fluid systems).\n"
        f"Task: Translate the values in the JSON object from Italian to {target_lang_name}.\n"
        "Strict Rules:\n"
        "1. Return ONLY valid JSON format.\n"
        "2. Keys must match the input keys EXACTLY.\n"
        "3. Preserve HTML tags (<p>, <div>, <span>) and structure EXACTLY.\n"
        "4. Do not translate proper names 'Fluid' or model codes.\n"
        "5. Keep technical terms accurate (e.g., 'Controtelaio' -> 'Counterframe'/'Châssis', 'Cartongesso' -> 'Drywall'/'Plaque de plâtre').\n"
        "6. If text is URL/filename, return it unchanged."
    )

    for attempt in range(MAX_RETRIES):
        try:
            completion = client.chat.completions.create(
                model=MODEL,
                messages=[
                    {"role": "system", "content": system_prompt},
                    {
                        "role": "user",
                        "content": f"Translate these to {target_lang_name}:\n\n{prompt_items}",
                    },
                ],
                response_format={"type": "json_object"},
                temperature=0.1,
                max_tokens=4000,
            )

            content = completion.choices[0].message.content
            return json.loads(content)

        except Exception as e:
            print(f"   ⚠️ Rate Limit/Error (Attempt {attempt + 1}): {e}")
            time.sleep(5)  # Wait longer on error

    return {}


def process_translation(input_file, output_file, target_lang):
    print(f"📂 Loading {input_file}...")
    if not os.path.exists(input_file):
        print("❌ File not found!")
        exit(1)

    with open(input_file, "r", encoding="utf-8") as f:
        data = json.load(f)

    client = get_client()

    # 1. Collect Items
    batch_buffer = {}
    mapping = {}

    print(f"🔍 Scanning for text to translate to {target_lang}...")

    for table, t_data in data.items():
        rows = t_data.get("rows", [])
        for r_idx, row in enumerate(rows):
            for field, value in row.items():
                if (
                    field in TRANSLATE_FIELDS
                    and isinstance(value, str)
                    and len(value.strip()) > 1
                ):
                    if value.startswith("http") or value.replace(".", "").isdigit():
                        continue

                    key = f"{table}::{r_idx}::{field}"

                    # Simple optimization: If converting to EN, check if already looks weird?
                    # No, rely on user providing correct source file.

                    batch_buffer[key] = value
                    mapping[key] = (table, r_idx, field)

    total_items = len(batch_buffer)
    print(f"📝 Found {total_items} items.")

    # 2. Process
    keys = list(batch_buffer.keys())
    total_batches = (len(keys) + BATCH_SIZE - 1) // BATCH_SIZE

    print(f"🚀 Starting translation...")

    current_batch = {}

    for i, key in enumerate(keys):
        current_batch[key] = batch_buffer[key]

        if len(current_batch) >= BATCH_SIZE or i == len(keys) - 1:
            batch_num = (i // BATCH_SIZE) + 1
            print(
                f"   ⚡ Batch {batch_num}/{total_batches} ({target_lang})...", end="\r"
            )

            results = translate_batch(client, current_batch, target_lang)

            for k, val in results.items():
                if k in mapping and val:
                    t, r, f = mapping[k]
                    data[t]["rows"][r][f] = val

            current_batch = {}

    print(f"\n✅ Translation to {target_lang} complete.")
    print(f"💾 Saving to {output_file}...")
    with open(output_file, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=4)


if __name__ == "__main__":
    # Example Usage:
    # python universal_translator.py --input db_content.json --output db_fr.json --lang "French"

    parser = argparse.ArgumentParser(description="Universal AI DB Translator")
    parser.add_argument("--input", required=True, help="Input JSON file path")
    parser.add_argument("--output", required=True, help="Output JSON file path")
    parser.add_argument(
        "--lang", default="English", help="Target Language (e.g. French, Spanish)"
    )

    args = parser.parse_args()

    process_translation(args.input, args.output, args.lang)
