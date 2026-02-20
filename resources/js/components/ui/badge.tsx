import { Slot } from "@radix-ui/react-slot"
import { cva, type VariantProps } from "class-variance-authority"
import * as React from "react"

import { cn } from "@/lib/utils"

const badgeVariants = cva(
  "inline-flex w-fit shrink-0 items-center justify-center gap-1 overflow-hidden whitespace-nowrap rounded-md border px-2 py-0.5 text-xs font-medium [&>svg]:pointer-events-none [&>svg]:size-3 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-warning aria-invalid:ring-warning/20 transition-[color,background-color,box-shadow]",
  {
    variants: {
      variant: {
        default:
          "border-transparent bg-info text-info-foreground [a&]:hover:bg-secondary-hover",
        info:
          "border-transparent bg-info text-info-foreground [a&]:hover:bg-secondary-hover",
        success:
          "border-transparent bg-success text-success-foreground [a&]:hover:bg-success-hover",
        warning:
          "border-transparent bg-warning text-warning-foreground [a&]:hover:bg-warning-hover",
        neutral:
          "border-transparent bg-muted text-muted-foreground [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
        secondary:
          "border-transparent bg-info text-info-foreground [a&]:hover:bg-secondary-hover",
        destructive:
          "border-transparent bg-warning text-warning-foreground [a&]:hover:bg-warning-hover",
        outline:
          "border-primary/25 text-primary [a&]:hover:bg-accent [a&]:hover:text-accent-foreground",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant,
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : "span"

  return (
    <Comp
      data-slot="badge"
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
