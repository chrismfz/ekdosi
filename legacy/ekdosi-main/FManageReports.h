//---------------------------------------------------------------------------

#ifndef FManageReportsH
#define FManageReportsH
//---------------------------------------------------------------------------
#include <Classes.hpp>
#include <Controls.hpp>
#include <StdCtrls.hpp>
#include <Forms.hpp>
#include "JvDBGrid.hpp"
#include "JvDBUltimGrid.hpp"
#include "JvExComCtrls.hpp"
#include "JvExDBGrids.hpp"
#include "JvExExtCtrls.hpp"
#include "JvExtComponent.hpp"
#include "JvPanel.hpp"
#include "JvToolBar.hpp"
#include <ComCtrls.hpp>
#include <DB.hpp>
#include <DBGrids.hpp>
#include <ExtCtrls.hpp>
#include <Grids.hpp>
#include <ImgList.hpp>
#include <ToolWin.hpp>
#include "JvRollOut.hpp"
#include "JvCheckBox.hpp"
#include "JvDBDotNetControls.hpp"
#include "JvDBSpinEdit.hpp"
#include "JvExControls.hpp"
#include "JvExMask.hpp"
#include "JvExStdCtrls.hpp"
#include "JvLookOut.hpp"
#include "JvSpin.hpp"
#include "JvToolEdit.hpp"
#include <DBCtrls.hpp>
#include <Mask.hpp>
#include "JvComCtrls.hpp"
#include "JvHtControls.hpp"
#include "JvRadioButton.hpp"
#include "JvDotNetControls.hpp"
#include "JvEdit.hpp"
#include "JvCombobox.hpp"

#include "CNewSpecialForm.h"
#include <IBX.IBCustomDataSet.hpp>
#include <System.ImageList.hpp>
//---------------------------------------------------------------------------
class TFrmManageReports : public NewSpecialForm
{
__published:	// IDE-managed Components
	TImageList *ImageList1;
	TDataSource *DSReports;
	TIBDataSet *DatasetReports;
	TJvPanel *JvPanel1;
	TJvToolBar *JvToolBar1;
	TToolButton *ToolPrevious;
	TToolButton *ToolNext;
	TToolButton *ToolButton4;
	TToolButton *ToolAdd;
	TToolButton *ToolDelete;
	TToolButton *ToolButton8;
	TToolButton *ToolEdit;
	TToolButton *ToolAccept;
	TToolButton *ToolCancel;
	TToolButton *ToolButton2;
	TToolButton *ToolRefresh;
	TJvPanel *PanelMain;
	TJvDBUltimGrid *GridReports;
	TStatusBar *StatusBar1;
	TJvRollOut *RollDetail;
	TLabel *Label1;
	TLabel *Label4;
	TJvDotNetDBEdit *editDescription;
	TJvFilenameEdit *editFilename;
	TJvCheckBox *checkShowOnMenu;
	TJvPageControl *PageControl;
	TTabSheet *TabSheet1;
	TTabSheet *TabSheet2;
	TJvPanel *JvPanel2;
	TJvDBUltimGrid *GridParams;
	TJvDotNetDBMemo *MemoDetails;
	TLabel *Label2;
	TIntegerField *DatasetReportsREPORT_ID;
	TIBStringField *DatasetReportsDESCRIPTION;
	TMemoField *DatasetReportsNOTES;
	TIBStringField *DatasetReportsFILENAME;
	TSmallintField *DatasetReportsSHOW_ON_MENU;
	TJvToolBar *JvToolBar2;
	TToolButton *ToolPPrevious;
	TToolButton *ToolPNext;
	TToolButton *ToolButton5;
	TToolButton *ToolPAdd;
	TToolButton *ToolPDelete;
	TToolButton *ToolButton9;
	TToolButton *ToolPEdit;
	TToolButton *ToolPAccept;
	TToolButton *ToolPCancel;
	TToolButton *ToolButton13;
	TToolButton *ToolPRefresh;
	TDataSource *DSRepParams;
	TIBDataSet *DatasetRepParams;
	TIntegerField *DatasetRepParamsINPUTDATA_ID;
	TIntegerField *DatasetRepParamsREPORT_ID;
	TJvDotNetDBEdit *editParamName;
	TLabel *Label3;
	TJvRadioButton *RadioFixed;
	TJvRadioButton *RadioSelector;
	TJvDotNetEdit *editVariable;
	TIBStringField *DatasetRepParamsVARIABLE_NAME;
	TIBStringField *DatasetRepParamsVAL;
	TJvComboBox *comboVariable;
	TToolButton *ToolButton1;
	TToolButton *ToolButton3;
	TJvExpressButton *buttonRunReport;
	TJvExpressButton *JvExpressButton1;
	void __fastcall DatasetReportsAfterScroll(TDataSet *DataSet);
	void __fastcall ToolAddClick(TObject *Sender);
	void __fastcall ToolDeleteClick(TObject *Sender);
	void __fastcall ToolEditClick(TObject *Sender);
	void __fastcall ToolAcceptClick(TObject *Sender);
	void __fastcall ToolCancelClick(TObject *Sender);
	void __fastcall DatasetReportsAfterEdit(TDataSet *DataSet);
	void __fastcall DatasetReportsAfterInsert(TDataSet *DataSet);
	void __fastcall DatasetReportsAfterPost(TDataSet *DataSet);
	void __fastcall DatasetReportsBeforeEdit(TDataSet *DataSet);
	void __fastcall DatasetReportsBeforeInsert(TDataSet *DataSet);
	void __fastcall FormCloseQuery(TObject *Sender, bool &CanClose);
	void __fastcall editFilenameChange(TObject *Sender);
	void __fastcall checkShowOnMenuClick(TObject *Sender);
	void __fastcall FormShow(TObject *Sender);
	void __fastcall ToolPPreviousClick(TObject *Sender);
	void __fastcall ToolPNextClick(TObject *Sender);
	void __fastcall ToolPAddClick(TObject *Sender);
	void __fastcall DatasetRepParamsBeforeOpen(TDataSet *DataSet);
	void __fastcall RadioFixedClick(TObject *Sender);
	void __fastcall RadioSelectorClick(TObject *Sender);
	void __fastcall DatasetRepParamsAfterScroll(TDataSet *DataSet);
	void __fastcall ToolPEditClick(TObject *Sender);
	void __fastcall ToolPDeleteClick(TObject *Sender);
	void __fastcall ToolPAcceptClick(TObject *Sender);
	void __fastcall ToolPRefreshClick(TObject *Sender);
	void __fastcall ToolPCancelClick(TObject *Sender);
	void __fastcall DatasetRepParamsAfterPost(TDataSet *DataSet);
	void __fastcall DatasetRepParamsAfterCancel(TDataSet *DataSet);
	void __fastcall DatasetRepParamsAfterOpen(TDataSet *DataSet);
	void __fastcall JvExpressButton1Click(TObject *Sender);
	void __fastcall buttonRunReportClick(TObject *Sender);
	void __fastcall ToolButton1Click(TObject *Sender);
private:	// User declarations
	
public:		// User declarations
	__fastcall TFrmManageReports(TComponent* Owner);
};
//---------------------------------------------------------------------------
extern PACKAGE TFrmManageReports *FrmManageReports;
//---------------------------------------------------------------------------
#endif
