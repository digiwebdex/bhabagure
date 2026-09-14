import { EditorContent, useEditor, useEditorState } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { useId } from 'react'
import { useTranslation } from 'react-i18next'

/**
 * Rich text limited to what the API keeps (App\Support\HtmlSanitizer: p h2 h3 strong em a ul ol li blockquote br).
 * Everything else in StarterKit is switched off, so what staff see is what the website shows.
 */
export function RichTextEditor({ label, value, onChange, error }: { label: string; value: string | null | undefined; onChange: (html: string) => void; error?: string }) {
  const { t } = useTranslation()
  const labelId = useId()

  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3] },
        code: false,
        codeBlock: false,
        horizontalRule: false,
        strike: false,
        underline: false,
        link: { openOnClick: false, autolink: true, protocols: ['http', 'https', 'mailto', 'tel'], HTMLAttributes: { rel: 'noopener noreferrer', target: null } },
      }),
    ],
    content: value ?? '',
    editorProps: {
      attributes: {
        'aria-labelledby': labelId,
        class: 'rich-text min-h-editor px-3.5 py-3 text-15 leading-1.65 outline-none',
      },
    },
    onUpdate: ({ editor: instance }) => onChange(instance.isEmpty ? '' : instance.getHTML()),
  })

  const state = useEditorState({
    editor,
    selector: ({ editor: instance }) => ({
      bold: instance?.isActive('bold') ?? false,
      italic: instance?.isActive('italic') ?? false,
      h2: instance?.isActive('heading', { level: 2 }) ?? false,
      h3: instance?.isActive('heading', { level: 3 }) ?? false,
      bullet: instance?.isActive('bulletList') ?? false,
      ordered: instance?.isActive('orderedList') ?? false,
      quote: instance?.isActive('blockquote') ?? false,
      link: instance?.isActive('link') ?? false,
    }),
  })

  if (!editor) return null

  const setLink = () => {
    const previous = editor.getAttributes('link').href as string | undefined
    const url = window.prompt(t('blog.linkPrompt'), previous ?? 'https://')
    if (url === null) return
    if (url.trim() === '' || url === 'https://') editor.chain().focus().extendMarkRange('link').unsetLink().run()
    else editor.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run()
  }

  const tool = (active: boolean, text: string, title: string, run: () => void) => (
    <button
      type="button"
      title={title}
      aria-label={title}
      aria-pressed={active}
      onMouseDown={(event) => event.preventDefault()}
      onClick={run}
      className={`min-w-8 cursor-pointer rounded-7 border px-2 py-1 text-13 font-semibold ${active ? 'border-blue bg-blue text-white' : 'border-app-line bg-app-surface text-app-text hover:border-blue'}`}
    >
      {text}
    </button>
  )

  return (
    <div className="flex flex-col gap-1.25">
      <span id={labelId} className="text-13 text-app-muted">
        {label}
      </span>
      <div className={`overflow-hidden rounded-9 border bg-app-surface-2 focus-within:border-blue ${error ? 'border-red' : 'border-app-line'}`}>
        <div role="toolbar" aria-label={t('blog.formatting')} className="flex flex-wrap gap-1 border-b border-app-line bg-app-surface px-2 py-1.5">
          {tool(state?.h2 ?? false, 'H2', t('blog.heading2'), () => editor.chain().focus().toggleHeading({ level: 2 }).run())}
          {tool(state?.h3 ?? false, 'H3', t('blog.heading3'), () => editor.chain().focus().toggleHeading({ level: 3 }).run())}
          {tool(state?.bold ?? false, 'B', t('blog.bold'), () => editor.chain().focus().toggleBold().run())}
          {tool(state?.italic ?? false, 'I', t('blog.italic'), () => editor.chain().focus().toggleItalic().run())}
          {tool(state?.bullet ?? false, '•', t('blog.bulletList'), () => editor.chain().focus().toggleBulletList().run())}
          {tool(state?.ordered ?? false, '1.', t('blog.orderedList'), () => editor.chain().focus().toggleOrderedList().run())}
          {tool(state?.quote ?? false, '“', t('blog.quote'), () => editor.chain().focus().toggleBlockquote().run())}
          {tool(state?.link ?? false, '🔗', t('blog.link'), setLink)}
        </div>
        <EditorContent editor={editor} />
      </div>
      {error ? (
        <span role="alert" className="text-12 font-semibold text-red">
          {error}
        </span>
      ) : null}
    </div>
  )
}
