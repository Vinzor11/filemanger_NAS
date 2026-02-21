import { LayoutGrid, Table2 } from 'lucide-react';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { type FileLayoutMode } from '@/hooks/use-file-layout-mode';

type LayoutModeToggleProps = {
    value: FileLayoutMode;
    onValueChange: (mode: FileLayoutMode) => void;
};

export function LayoutModeToggle({ value, onValueChange }: LayoutModeToggleProps) {
    return (
        <ToggleGroup
            type="single"
            value={value}
            variant="outline"
            size="sm"
            onValueChange={(nextValue) => {
                if (nextValue === 'table' || nextValue === 'context') {
                    onValueChange(nextValue);
                }
            }}
            aria-label="File layout mode"
        >
            <ToggleGroupItem value="table" aria-label="Table layout">
                <Table2 className="size-4" />
                <span className="hidden sm:inline">Table</span>
            </ToggleGroupItem>
            <ToggleGroupItem value="context" aria-label="Context layout">
                <LayoutGrid className="size-4" />
                <span className="hidden sm:inline">Context</span>
            </ToggleGroupItem>
        </ToggleGroup>
    );
}
